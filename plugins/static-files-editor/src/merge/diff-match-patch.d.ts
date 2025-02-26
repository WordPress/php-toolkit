declare module 'diff-match-patch' {
    export const DIFF_DELETE = -1;
    export const DIFF_INSERT = 1;
    export const DIFF_EQUAL = 0;

    export type Operation = typeof DIFF_DELETE | typeof DIFF_INSERT | typeof DIFF_EQUAL;
    export type Diff = [Operation, string];
    export type patch_obj = {
        diffs: Diff[];
        start1: number;
        start2: number;
        length1: number;
        length2: number;
    };

    export class diff_match_patch {
        diff_main(text1: string, text2: string): Diff[];
        diff_cleanupSemantic(diffs: Diff[]): void;
        patch_make(text1: string, diffs: Diff[]): patch_obj[];
        patch_apply(patches: patch_obj[], text: string): [string, boolean[]];
    }
}
