declare module '@wordpress/element' {
    export { useEffect } from 'react';
    export const createRoot: any;
}

declare module '@wordpress/editor' {
    export const store: any;
    export const ErrorBoundary: any;
}

declare module '@wordpress/preferences' {
    export const store: any;
}

declare module '@wordpress/data' {
    export const register: any;
    export const dispatch: any;
    export const select: any;
    export const resolveSelect: any;
    export const subscribe: any;
    export const useSelect: any;
	export const useDispatch: any;
	export const createReduxStore: any;
}

declare module '@wordpress/api-fetch' {
    const apiFetch: any;
    export default apiFetch;
}

declare module '@wordpress/notices' {
    export const store: any;
}

declare module '@wordpress/block-editor' {
    export const store: any;
}

declare module '@wordpress/blocks' {
    export const parse: any;
    export const serialize: any;
}

declare module '@wordpress/components' {
    export const Button: any;
    export const Spinner: any;
    export const Icon: any;
	export const ToolbarButton: any;
	export const ToolbarItem: any;
	export const DropdownMenu: any;
}

declare module '@wordpress/icons' {
    export const undo: any;
    export const redo: any;
    export const chevronLeft: any;
    export const chevronLeftSmall: any;
	export const moreVertical: any;
	export const sidebar: any;
}

declare module '@wordpress/edit-post' {
    export const store: any;
}

declare module '@wordpress/core-data' {
    export const store: any;
}

declare module './style.module.css' {
    const styles: {
        readonly ['file-picker-tree-container']: string;
        readonly ['insideEditorToolbar']: string;
        readonly ['editorToolbarLeft']: string;
        readonly ['editorToolbarRight']: string;
        readonly ['syncButton']: string;
        readonly ['iconFade']: string;
    };
    export default styles;
}
