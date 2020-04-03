/**
 * @flow
 */
import { setFailed, getInput } from '@actions/core';
import '@actions/github';
import { readFile, createReadStream } from 'fs';
import { Octokit } from '@octokit/action';
import { join } from 'path';

import type { Reporter } from './reporter';
import psalm from './psalm';
import typescript from './typescript';
import eslint from './eslint';

export const octokit = new Octokit();

try {
    const repository = process.env['GITHUB_REPOSITORY'];
    if ( repository == null ) {
        throw new Error( 'Missing GITHUB_REPOSITORY' );
    }
    const [owner, repo] = repository.split('/');

    const path = getInput('report_path');
    const headSha = process.env['GITHUB_SHA'];
    const workspaceDirectory = process.env['GITHUB_WORKSPACE'];

    const relativeDirectory = getInput('src_directory');

    if (headSha == null) {
        throw new Error('GITHUB_SHA no present');
    }

    if (workspaceDirectory == null) {
        throw new Error('GITHUB_WORKSPACE not present');
    }

    const reporter = selectReporter(getInput('report_type'));

    if (!reporter) {
        throw new Error('Unknown report type: ' + getInput('report_type'));
    }

    Promise.resolve(createReadStream(path, {autoClose: true, emitClose: true}).pause())
        .then((stream) => reporter({
            owner,
            repo,
            reportName: getInput('report_name'),
            reportTitle: getInput('report_title'),
            headSha,
            workspaceDirectory: trailingSlash(workspaceDirectory),
            relativeDirectory,
            reportContents: stream,
        }))
        .then(async report => {
            // make a request for every 50 annotations, first one to create the report, remaining to update it
            const annotations = report.output.annotations.slice();
            const initial = annotations.slice(0, 50);
            let remaining = annotations.slice(50);

            console.log('initial annotations', initial.length, initial);
            const checkRun = await octokit.checks.create({
                ...report,
                status: 'in_progress',
                output: {
                    ...report.output,
                    annotations: initial
                }
            });

            while(remaining.length > 0) {
                const next = remaining.slice(0, 50);
                console.log('sending annotations', next.length, next);
                await octokit.checks.update({
                    owner: report.owner,
                    repo: report.repo,
                    check_run_id: checkRun.data.id,
                    status: 'in_progress',
                    output: {
                        ...report.output,
                        annotations: next,
                    }
                });
                remaining = remaining.slice(50);
            }

            await octokit.checks.update({
                owner: report.owner,
                repo: report.repo,
                check_run_id: checkRun.data.id,
                conclusion: 'neutral',
                output: {
                    ...report.output,
                    annotations: []
                }
            });
        })
        .then(
            (result: any) => console.log('success', result.data.url),
            error => setFailed(error.message)
        );
} catch (error) {
    setFailed(error.message);
}

function main( path ) {
    return readContents( path );
}

function readContents( path ): Promise<Buffer> {
    return new Promise((resolve, reject) => {
        readFile(path, (error, data) => {
            if (error != null) {
                reject(error);
                return;
            }
            resolve(data);
        });

    });
}

function trailingSlash(path: void | null | string): string {
    if ( path == null ) {
        return '';
    }

    return path.slice(-1) === '/' ? path : path + '/';
}

function selectReporter(type: string): ?Reporter {
    console.log('Using reporter for', type);
    switch(type) {
        case 'typescript': {
            return typescript;
        }
        case 'eslint': {
            return eslint;
        }
        case 'psalm':
        case '': {
            return psalm
        }
        default: {
            throw new Error('Reporter not known ' + type);
        }
    }
}
