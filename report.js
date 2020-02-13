/**
 * @flow
 */

const core = require('@actions/core');
const github = require('@actions/github');
const { readFile } = require('fs');
const { Octokit } = require('@octokit/action');

export const octokit = new Octokit();


try {
    const repository = process.env['GITHUB_REPOSITORY'];
    if ( repository == null ) {
        throw new Error( 'Missing GITHUB_REPOSITORY' );
    }
    const [owner, repo] = repository.split('/');

    const path = core.getInput('report_path');
    main(path)
        .then(buffer => buffer)
        .then(mapWith(createCheckRun(owner, repo)))
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

function mapWith<T, P>(creator: () => Promise<T>): (P) => Promise<[P, T]> {
    return function(resolved: P) {
        return creator().then(
            created => [resolved, created]
        );
    }
}

/**
 * https://help.github.com/en/actions/configuring-and-managing-workflows/using-environment-variables#default-environment-variables
 */

export function createCheckRun(owner: string, repo: string): () => Promise<*> {
    return () => octokit.request(
        'POST /repos/:owner/:repo/check-runs',
        { 
            owner,
            repo,
            headers: {
                accept: 'application/vnd.github.antiope-preview+json',
                'content-type': 'application/json'
            },
            name: 'psalm',
            head_sha: process.env['GITHUB_SHA'],
            status: 'completed',
            conclusion: 'neutral'  
        }
    )
}