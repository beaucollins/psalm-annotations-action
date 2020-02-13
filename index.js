const core = require('@actions/core');
const github = require('@actions/github');

try {
    const path = core.getInput('report_path');
    core.setFailed('Not implemented');
} catch (error) {
    core.setFailed(error.message);
}

/**
 * 
 * @param {string} path 
 */
function readContents( path ) {
    throw new Error('not implemented');
}