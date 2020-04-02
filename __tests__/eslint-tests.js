import { createReadStream } from 'fs';
import createReport from '../src/eslint';

describe('eslint', () => {
	it('parses report', async () => {
		const report = await createReport({
			repo: 'repo',
			owner: 'owner',
			headSha: 'sha-hash',
			reportTitle: 'eslint report',
			reportName: 'eslint',
			reportContents: createReadStream(__dirname + '/eslint.json'),
			relativeDirectory: 'eek/',
		});

		expect(report.output.annotations.length).toEqual(1);
	})
});