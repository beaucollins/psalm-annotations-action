/**
 * @flow
 */
import type { Annotation, Check, Reporter } from './reporter';
import { parseJsonStream } from './collect-buffers';

const parseJson = () => {

}

const reporter: Reporter = async (options) => {
	return {
		owner: options.owner,
		repo: options.repo,
		name: options.reportName,
		head_sha: options.headSha,
		status: 'completed',
		conclusion: 'neutral',
		output: {
			title: options.reportTitle,
			summary: '',
			annotations: await createAnnotations(options.reportContents, options.workspaceDirectory),
		}
	};
}

async function createAnnotations(stream, prefix): Promise<Annotation[]> {
	const json = await parseJsonStream(stream);
	return json.reduce((annotations: Annotation[], file): Annotation[] => {
		return [...annotations, ...file.messages.map(message => messageToAnnotation(file, message, prefix))];
	}, []);
}

function messageToAnnotation(file, message, prefix): Annotation {
	const base: Annotation = {
		title: message.ruleId,
		annotation_level: noticeLevel(message.severity),
		start_line: message.line,
		end_line: message.endLine ?? message.line,
		path: file.filePath.slice(prefix.length),
		raw_details: JSON.stringify(message, null, ' '),
		message: message.message,
	};
	const columns: ?({|start_column: number, end_column: number|}) = message.column && message.endColumn
		? {
			start_column: message.column,
			end_column: message.endColumn,
		}
		: null;

	if (columns && base.start_line === base.end_line) {
		return {...base, ...columns};
	}
	return base;
}

function noticeLevel(severity) {
	switch(severity) {
		case 1: {
			return 'warning';
		}
		case 2: {
			return 'failure';
		}
		default: {
			return 'notice';
		}
	}

}

export default reporter;
