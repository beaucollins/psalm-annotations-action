/**
 * @flow
 */
import core from '@actions/core';
import github from '@actions/github';
import { readFile } from 'fs';
import { Octokit } from '@octokit/action';

import { mapLevel, mapAnnotation, createCheckRun } from './src/psalm';

export const octokit = new Octokit();

try {
    const repository = process.env['GITHUB_REPOSITORY'];
    if ( repository == null ) {
        throw new Error( 'Missing GITHUB_REPOSITORY' );
    }
    const [owner, repo] = repository.split('/');

    const path = core.getInput('report_path');
    const headSha = process.env['GITHUB_SHA'];
    const workspaceDir = process.env['GITHUB_WORKSPACE'];

    if (headSha == null) {
        throw new Error('GITHUB_SHA no present');
    }

    if (workspaceDir == null) {
        throw new Error('GITHUB_WORKSPACE not present');
    }

    main(path)
        .then(buffer => JSON.parse(buffer.toString('utf-8')))
        .then((json) => createCheckRun(
            owner,
            repo,
            core.getInput('report_name'),
            core.getInput('report_title'),
            headSha,
            workspaceDir,
            json
        ))
        .then(octokit.checks.create)
        .then(
            result => console.log('success', result),
            error => core.setFailed(error.message)
        );
} catch (error) {
    core.setFailed(error.message);
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
