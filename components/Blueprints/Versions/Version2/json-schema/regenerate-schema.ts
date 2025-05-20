import type Config from "ts-json-schema-generator";
import { createGenerator } from "ts-json-schema-generator";
import { writeFileSync } from "fs";
import { fileURLToPath } from "url";
import { dirname, resolve } from "path";

const scriptDir = dirname( fileURLToPath( import.meta.url ) );
const entry = resolve( scriptDir, "wsp/wsp-1-blueprint-v2-schema/appendix-A-blueprint-v2-schema.ts" );
const out = resolve( scriptDir, "schema-v2.json" );

const cfg: Config = {
  path: resolve( entry ),
  tsconfig: resolve( scriptDir, "tsconfig.json" ),
  type: "Blueprint",
  additionalProperties: false,
  skipTypeCheck: false,
  
};

const schema = createGenerator(cfg).createSchema("Blueprint");
/**
 * The ts-json-schema-generator library converts string | false to "boolean".
 * We need to convert it back to string | false.
 */
schema.definitions.Blueprint.properties.siteOptions.properties.permalink_structure = {
	anyOf: [
		{
			type: "boolean",
			enum: [false],
		},
		{
			type: "string",
		},
	],
};

function resolveRef(ref: string): any {
  if (!ref.startsWith("#/definitions/")) {
    throw new Error(`Unsupported $ref format: ${ref}`);
  }
  const defName = ref.replace("#/definitions/", "");
  const def = schema.definitions[defName];
  if (!def) {
    throw new Error(`Definition not found for $ref: ${ref}`);
  }
  return def;
}

const steps = schema.definitions.Blueprint.properties.additionalStepsAfterExecution.items;

// Validation: Assert that each step type has a unique "step" value (via "const" or "enum")
const seenStepValues = new Set<string>();
if (Array.isArray(steps)) {
  for (let stepSchema of steps) {
    // Resolve $ref if present
    if (stepSchema && stepSchema.$ref) {
      stepSchema = resolveRef(stepSchema.$ref);
    }
    if (!stepSchema || stepSchema.type !== "object") {
      throw new Error(
        `Each step schema must be an object type. Found: ${JSON.stringify(stepSchema)}`
      );
    }
    if (stepSchema.properties && stepSchema.properties.step) {
      const stepProp = stepSchema.properties.step;
      let values: string[] = [];
      if (typeof stepProp.const === "string") {
        values = [stepProp.const];
      } else if (Array.isArray(stepProp.enum)) {
        values = stepProp.enum;
      }
      for (const val of values) {
        if (seenStepValues.has(val)) {
          throw new Error(
            `Duplicate step value "${val}" found in additionalStepsAfterExecution.items. Each step value must be unique.`
          );
        }
        seenStepValues.add(val);
      }
    }
  }
}
					
const json = JSON.stringify( schema, null, 2 );

writeFileSync( out, json );
console.log( `Updated JSON schema written to ${out}` );
