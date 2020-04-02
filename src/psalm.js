/**
 * @flow
 */
import type { Annotation, Check, Reporter } from './reporter';
import { parseJsonStream } from './collect-buffers';

type Issue = {|
    severity: 'info' | 'error',
    line_from: number,
    line_to: number,
    type: string,
    message: string,
    file_name: string,
    file_path: string,
    snippet: string,
    selected_text: string,
    from: number,
    to: number,
    snipped_from: 167,
    snippet_to: 198,
    column_from: 23,
    column_to: 29
|}

function mapLevel(issue: Issue): $PropertyType<Annotation, 'annotation_level'> {
    switch(issue.severity) {
        case 'info':
            return 'warning';
        case 'error':
            return 'failure';
        default: {
            return 'notice';
        }
    }
}

function mapAnnotation(pathPrefix: string = ''): Issue => Annotation {
    return issue => ({
        path: issue.file_path.slice(pathPrefix.length),
        annotation_level: mapLevel(issue),
        start_line: issue.line_from,
        end_line: issue.line_to,
        message: issue.message,
        start_column: issue.column_from,
        end_column: issue.column_to,
        title: issue.type,
        raw_details: issue.snippet
    });
}

function createCheckRun(
	owner: string,
	repo: string,
	reportName: string,
	reportTitle: string,
	headSha: string,
	workingDirectory: string,
	issues: Issue[],
): Check {
	const mapper = mapAnnotation(workingDirectory);
	return {
		owner,
		repo,
		name: reportName,
		head_sha: headSha,
		status: 'completed',
		conclusion: 'neutral',
		output: {
			title: reportTitle,
			summary: 'PHP Static type Analysis by [Psalm](http://psalm.dev)',
			annotations: issues.map(mapper)
		}
	}
}

const reporter: Reporter = async (options) => {
	return createCheckRun(
		options.owner,
		options.repo,
		options.reportName,
		options.reportTitle,
		options.headSha,
		options.workspaceDirectory,
		await parseJsonStream(options.reportContents)
	);
}

export default reporter;
