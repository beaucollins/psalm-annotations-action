/**
 * @flow
 */
import type { Reporter, Annotation } from './reporter';
import { join } from 'path';

const errorPattern = /([^(]{1,})\(([\d]{1,}),([\d]{1,})\):([^:]{1,}):(.*)/i

function read(onLine: (line: string) => Promise<void>, stream) {
	return new Promise((resolve, reject) => {
		stream.once('readable', async () => {
			let buffer;
			while(buffer = stream.read()) {
				if (buffer instanceof Buffer) {
					buffer = buffer.toString('utf8');
				}
				const lines = buffer.split('\n');
				for(const line of lines) {
					await onLine(line);
				}
			}
			resolve([]);
		});
	});
}

type Issue = {|
	full: string,
	path: string,
	line: number,
	column: number,
	message: string,
	code: string,
	extra?: string
|}

function mapIssue(issue): Annotation {
	return {
		path: issue.path,
		annotation_level: 'failure',
		start_line: issue.line,
		end_line: issue.line,
		message: issue.message,
		start_column: issue.column,
		end_column: issue.column,
		raw_details: issue.extra ? issue.full.concat( "\n", issue.extra ) : issue.full,
	};
}

function parseReport(stream, relativeDirectory): Promise<Annotation[]> {
	const annotations: Annotation[] = [];
	let issue: ?Issue = null;
	return read(
		async (line) => {
			let match;
			if (match = errorPattern.exec(line)) {
				if (issue) {
					annotations.push(mapIssue(issue));
				}
				const [full, file, line, column, code, message] = match;
				issue = {
					full,
					line: parseInt(line),
					column: parseInt(column),
					path: join(relativeDirectory, file),
					message: message.trim(),
					code: code.trim(),
				};
			} else if (issue) {
				issue = {
					...issue,
					extra: issue.extra ? issue.extra.concat("\n", line) : line
				};
			}
		},
		stream
	).then((stats) => {
		if (issue) {
			annotations.push(mapIssue(issue));
		}
		return annotations;
	});
}

const reporter: Reporter = async (options) => {
	const {
		repo,
		owner,
		headSha: head_sha,
	} = options;
	return {
		repo,
		owner,
		head_sha,
		name: options.reportName,
		status: 'completed',
		conclusion: 'neutral',
		output: {
			title: options.reportTitle,
			summary: 'TypeScript Report',
			annotations: await parseReport(options.reportContents, options.relativeDirectory)
		}
	};
}

export default reporter;