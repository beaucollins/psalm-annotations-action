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

		expect(report.repo).toEqual('repo');
		expect(report.owner).toEqual('owner');
		expect(report.head_sha).toEqual('sha-hash');
		expect(report.name).toEqual('eslint');
		expect(report.status).toEqual('completed');
		expect(report.conclusion).toEqual('neutral');

		expect(report.output.annotations.length).toEqual(1);
	})
});