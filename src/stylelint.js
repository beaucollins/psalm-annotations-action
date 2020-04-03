/**
 * @flow
 */
import type { Annotation, Check, Reporter } from './reporter';
import { parseJsonStream } from './collect-buffers';

const stylelint: Reporter = async (options) => {
	const json = await parseJsonStream(options.reportContents);
	return {
		repo: options.repo,
		owner: options.owner,
		head_sha: options.headSha,
		name: options.reportName,
		status: 'completed',
		conclusion: 'neutral',
		output: {
			title: options.reportTitle,
			summary: '',
			annotations: createAnnotations( json ),
		}
	}
}

function createAnnotations(report): Annotation[] {
	return report.reduce(
		(annotations: Annotation[], fileReport) => {
			return [...annotations, ...fileAnnotations(fileReport)];
		},
		[]
	);
}

function fileAnnotations(file): Annotation[] {
	return file.warnings.map((warning): Annotation => ({
		annotation_level: warning.severity === 'error' ? 'failure' : 'warning',
		start_line: warning.line,
		end_line: warning.line,
		start_column: warning.column,
		path: file.source,
		message: warning.text,
		title: warning.rule,
	}));
}

export default stylelint;