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
			reportContents: createReadStream(__dirname + '/report.txt'),
			relativeDirectory: 'some/path',
		});

		expect(report.repo).toBe('my-repo');
		expect(report.owner).toBe('some-owner');
		expect(report.head_sha).toBe('some-sha');
		expect(report.name).toBe('tsc');
		expect(report.status).toBe('completed');
		expect(report.conclusion).toBe('neutral');

		expect(report.output.summary).toBe('TypeScript Report');
		expect(report.output.title).toBe('some title');

		expect(report.output.annotations.length).toEqual(5);
	});
});
