import http from 'node:http';

const server = http.createServer((req, res) => {
  if (req.url && req.url.includes('manifest')) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'not_found' }));
    return;
  }
  const body = JSON.stringify({
    data: [],
    next_cursor: null,
    total: 0,
  });
  res.writeHead(200, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Connection': 'keep-alive',
  });
  res.end(body);
});

server.listen(0, '127.0.0.1', () => {
  const addr = server.address();
  const port = typeof addr === 'object' && addr ? addr.port : 0;
  // Output port for parent process
  process.stdout.write(`PORT:${port}\n`);
});
