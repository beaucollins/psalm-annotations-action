import parseReport from '../src/stylelint';
import { createReadStream } from 'fs';

describe('stylelint', () => {
	it('parses report', async () => {
		const report = await parseReport({
			reportContents: createReadStream(__dirname + '/stylelint-report.json')
		});

		expect(report.output.annotations.length).toEqual(8);
	});
});