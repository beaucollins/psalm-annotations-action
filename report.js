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
        .then(buffer => JSON.parse(buffer.toString('utf-8')))
        .then(createCheckRun(owner, repo))
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

/**
 * https://help.github.com/en/actions/configuring-and-managing-workflows/using-environment-variables#default-environment-variables
 */

export function createCheckRun(owner: string, repo: string): (any) => Promise<*> {
    const mapper: Issue => Annotation = log('annotate')(mapAnnotation(trailingSlash(process.env['GITHUB_WORKSPACE'])));
    return (issues: Array<Issue>) => {
        return octokit.checks.create({
            owner,
            repo,
            name: 'psalm',
            head_sha: process.env['GITHUB_SHA'],
            status: 'completed',
            conclusion: 'neutral',
            output: {
                title: 'Psalm PHP Static Analysis',
                summary: 'PHP Static type Analysis by [Psalm](http://psalm.dev)',
                annotations: issues.map(mapper)
            }
        });
    };
}

type Annotation = {|
    /**
     * The level of the annotation. Can be one of `notice`, `warning`, or `failure`.
     */
    annotation_level: "notice" | "warning" | "failure";
    /**
     * The end column of the annotation. Annotations only support `start_column` and `end_column` on the same line. Omit this parameter if `start_line` and `end_line` have different values.
     */
    end_column?: number;
    /**
     * The end line of the annotation.
     */
    end_line: number;
    /**
     * A short description of the feedback for these lines of code. The maximum size is 64 KB.
     */
    message: string;
    /**
     * The path of the file to add an annotation to. For example, `assets/css/main.css`.
     */
    path: string;
    /**
     * Details about this annotation. The maximum size is 64 KB.
     */
    raw_details?: string;
    /**
     * The start column of the annotation. Annotations only support `start_column` and `end_column` on the same line. Omit this parameter if `start_line` and `end_line` have different values.
     */
    start_column?: number;
    /**
     * The start line of the annotation.
     */
    start_line: number;
    /**
     * The title that represents the annotation. The maximum size is 255 characters.
     */
    title?: string;
|}

type Issue = {|
    severity: 'info' | 'error',
    line_from: number,
    line_to: number,
    type: string,
    message: string,
    file_name: string,
    file_path: string,
    snippet: string,
    selected_text: string,
    from: number,
    to: number,
    snipped_from: 167,
    snippet_to: 198,
    column_from: 23,
    column_to: 29
|}

function mapLevel(issue: Issue): $PropertyType<Annotation, 'annotation_level'> {
    switch(issue.severity) {
        case 'info':
            return 'warning';
        case 'error':
            return 'failure';
        default: {
            return 'notice';
        }
    }
}

function mapAnnotation(pathPrefix = ''): Issue => Annotation {
    return issue => ({
        path: issue.file_path.slice(pathPrefix.length),
        annotation_level: mapLevel(issue),
        start_line: issue.line_from,
        end_line: issue.line_to,
        message: issue.message,
        start_column: issue.column_from,
        end_column: issue.column_to,
        title: issue.type
    });
}

function log<T:Array<*>, R>(label: string): ((...T) => R) => (...T) => R {
    return (fn) => {
        return (...args) => {
            const r = fn(...args)
            console.log('label', ...args, r);
            return r;
        };
    }
}

function trailingSlash($path: void | null | string): string {
    if ( $path == null ) {
        return '';
    }

    return $path.slice(-1) === '/' ? $path : $path + '/';

}