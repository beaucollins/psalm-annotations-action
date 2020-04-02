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
			annotations: await createAnnotations(options.reportContents),
		}
	};
}

async function createAnnotations(stream): Promise<Annotation[]> {
	const json = await parseJsonStream(stream);
	return json.reduce((annotations: Annotation[], file): Annotation[] => {
		return [...annotations, ...file.messages.map(message => messageToAnnotation(file, message))];
	}, []);
}

function messageToAnnotation(file, message): Annotation {
	console.log('annotation for', file, message)
	const annotation = {
		title: message.ruleId + ' ' + message.message,
		annotation_level: 'notice',
		start_column: message.column,
		end_column: message.endColumn,
		start_line: message.start_line,
		end_line: message.endLine,
		path: file.filePath,
		raw_details: JSON.stringify(message, null, ' '),
		message: message.message,
	};
	console.log(annotation);
	return annotation;
}

export default reporter;
