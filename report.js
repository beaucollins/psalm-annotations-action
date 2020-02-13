const core = require('@actions/core');
const github = require('@actions/github');
const { readFile } = require('fs');

try {
    const path = core.getInput('report_path');
    main(path)
        .then(buffer => buffer.toString('utf8'))
        .then(JSON.parse)
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

/**
 * 
 * @param {string} path 
 */
function readContents( path ) {
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
