/**
 * @flow
 */
import { setFailed, getInput } from '@actions/core';
import '@actions/github';
import { readFile } from 'fs';
import { Octokit } from '@octokit/action';

import createCheck from './psalm';

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

    main(path)
        .then((buffer) => createCheck({
            owner,
            repo,
            reportName: getInput('report_name'),
            reportTitle: getInput('report_title'),
            headSha,
            workspaceDirectory,
            reportContents: buffer,
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
