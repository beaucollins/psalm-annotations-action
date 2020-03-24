/**
 * @flow
 */
import type { ReadStream } from 'fs';

export type Annotation = {|
    /**
     * The level of the annotation. Can be one of `notice`, `warning`, or `failure`.
     */
    annotation_level: "notice" | "warning" | "failure";
    /**
     * The end column of the annotation. Annotations only support `start_column` and `end_column` on the same line. Omit this parameter if `start_line` and `end_line` have different values.
     */
    end_column?: number;
    /**
     * The end line of the annotation.
     */
    end_line: number;
    /**
     * A short description of the feedback for these lines of code. The maximum size is 64 KB.
     */
    message: string;
    /**
     * The path of the file to add an annotation to. For example, `assets/css/main.css`.
     */
    path: string;
    /**
     * Details about this annotation. The maximum size is 64 KB.
     */
    raw_details?: string;
    /**
     * The start column of the annotation. Annotations only support `start_column` and `end_column` on the same line. Omit this parameter if `start_line` and `end_line` have different values.
     */
    start_column?: number;
    /**
     * The start line of the annotation.
     */
    start_line: number;
    /**
     * The title that represents the annotation. The maximum size is 255 characters.
     */
    title?: string;
|}

export type Check = {|
	owner: string,
	repo: string,
	name: string,
	head_sha: string,
	status: 'completed',
	conclusion: 'neutral',
	output: {
		title: string,
		summary: string,
		annotations: Annotation[],
	}
|}

type Options = {|
	owner: string,
	repo: string,
	reportName: string,
	reportTitle: string,
	workspaceDirectory: string,
	headSha: string,
	reportContents: ReadStream,
|}

export type Reporter = (Options) => (Promise<Check> | Check);
