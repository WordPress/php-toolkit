interface WordPress {
    element: any;
    blockEditor: any;
    dataLiberationCreateElementDecorator: (fn: Function) => Function;
    blocks?: {
        parse(content: string): unknown[];
    };
    blockSerializationDefaultParser?: {
        parse(content: string): unknown[];
    };
}

declare global {
    interface Window {
        wp: WordPress;
    }
}

export {};
