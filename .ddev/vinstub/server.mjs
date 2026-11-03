import { createServer } from 'node:http';

const port = Number(process.env.PORT ?? 3000);
const delayMs = Number(process.env.VINSTUB_DELAY_MS ?? 300);

createServer((req, res) => {
    const match = /^\/vin\/([^/?]+)/.exec(req.url ?? '');

    if (req.method !== 'GET' || match === null) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end('{"message":"not found"}');

        return;
    }

    let vin;

    try {
        vin = decodeURIComponent(match[1]);
    } catch {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end('{"message":"bad request"}');

        return;
    }

    setTimeout(() => {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ vin, make_check: 'ok', recalls: 0, source: 'vinstub' }));
    }, delayMs);
}).listen(port, '0.0.0.0');
