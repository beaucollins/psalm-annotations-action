/**
 * @flow
 */
import type { ReadStream } from 'fs';

export default function collectBuffers(stream: ReadStream): Promise<Buffer> {
    return new Promise((resolve, reject) => {
        const buffers: Buffer[] = [];
        stream
            .on('data', (data) => {
                buffers.push(data);
            })
            .on('close', () => {
                resolve(Buffer.concat(buffers));
            })
            .on('error', reject)
            .resume();
    })
}

export function parseJsonStream(stream: ReadStream): Promise<any> {
	return collectBuffers(stream).then(buffer => JSON.parse(buffer.toString('utf8')))
}