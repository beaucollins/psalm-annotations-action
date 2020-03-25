/**
 * @flow
 */
import { setFailed, getInput } from '@actions/core';
import '@actions/github';
import { readFile, createReadStream } from 'fs';
import { Octokit } from '@octokit/action';

import type { Reporter } from './reporter';
import psalm from './psalm';
import typescript from './typescript';

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
            reportContents: stream,
        }))
        .then(octokit.checks.create)
        .then(
            result => console.log('success', result),
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

function trailingSlash($path: void | null | string): string {
    if ( $path == null ) {
        return '';
    }

    return $path.slice(-1) === '/' ? $path : $path + '/';
}


function selectReporter(type: string): ?Reporter {
    switch(type) {
        case 'typescript': {
            return typescript;
        }
        case 'psalm':
        default: {
            return psalm
        }
    }
}