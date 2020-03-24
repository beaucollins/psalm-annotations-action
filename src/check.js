/**
 * @flow
 */

import type { Annotation } from './annotation';

export type Check = {|
	owner: string,
	repo: string,
	name: string,
	head_sha: string,
	status: 'completed',
	conclusion: 'neutral',
	output: {
		title: string,
		summary: string,
		annotations: Annotation[],
	}
|}
