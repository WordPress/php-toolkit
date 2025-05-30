export type FileNode = {
	name: string;
	type: 'file' | directory;
	children?: FileNode[];
	content?: File;
};

export type FileSubtree = {
	path: string;
	children: FileNode[];
};
