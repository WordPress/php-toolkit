import { FileNode } from '.';

export function isTopLevelPath(path: string) {
	return path.match(/^\/[^/]+$/);
}

export function getParentPath(path: string) {
	return path.split('/').slice(0, -1).join('/') || '/';
}

export function getNodeByPath(
	treeRoot: FileNode,
	path: string
): FileNode | null {
	const parentPath = path.replace(/^\//, '');
	if (!parentPath) {
		return treeRoot;
	}

	const parentSegments = parentPath.split('/');
	let treeLevel = treeRoot;
	for (const segment of parentSegments) {
		treeLevel = treeLevel.children?.find((node) => node.name === segment);
		if (!treeLevel) {
			return null;
		}
	}
	return treeLevel;
}
