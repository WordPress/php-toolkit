export class MergeConflict {
    constructor(
        public branchA: string,
        public branchB: string,
        public options: { message?: string, mergedContent?: string } = {}
    ) {}

    get message(): string | undefined {
        return this.options.message;
    }
}

export class MergeResult {
    public mergedContent: string;
    public hasConflicts: boolean;
    public conflicts: MergeConflict[];

    constructor(results: (string | MergeConflict)[]) {
        this.conflicts = results.filter((r): r is MergeConflict => r instanceof MergeConflict);
        this.hasConflicts = this.conflicts.length > 0;
        this.mergedContent = this.hasConflicts ? '' : results.join('');
    }

    toString(): string {
        return this.mergedContent;
    }
}

export class MergeException extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'MergeException';
    }
}

export class InvalidMergeException extends MergeException {
    constructor(message: string) {
        super(message);
        this.name = 'InvalidMergeException';
    }
}
