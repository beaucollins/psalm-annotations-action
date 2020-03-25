import { createReadStream } from 'fs';
import createReport from '../src/typescript';

describe('typescript report', () => {
	it('parses report', async () => {
		const report = await createReport({
			repo: 'my-repo',
			owner: 'some-owner',
			headSha: 'some-sha',
			reportTitle: 'some title',
			reportName: 'tsc',
			reportContents: createReadStream(__dirname + '/report.txt')
		});

		expect(report).toEqual({
			repo: 'my-repo',
			owner: 'some-owner',
			head_sha: 'some-sha',
			name: 'tsc',
			status: 'completed',
			conclusion: 'neutral',
			output: {
				annotations: [],
				summary: 'TypeScript Report',
				title: 'some title'
			}
		});
	});
});
