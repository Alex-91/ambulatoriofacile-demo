"""Temporary Windows-loopback relay to one verified synthetic Docker app.

No published Docker ports, network-setting changes, external destination or
authentication bypass. Browser still performs the app's normal login/CSRF.
Bodies/cookies/passwords are never logged. Run only during the UI exercise.
"""
import base64
import importlib.util
import json
import re
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

spec = importlib.util.spec_from_file_location('fse_instance', Path(__file__).with_name('linux-ui-resilience.py'))
instance_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(instance_tools)
MAX_BODY = 16 * 1024 * 1024
REMOTE = '''import base64,http.client,json,sys
from pathlib import Path
if not Path('/opt/fse/linux-lab-image').is_file(): raise RuntimeError('Linux lab required')
q=json.loads(sys.stdin.buffer.read(24000000))
if q['method'] not in ('GET','POST','HEAD') or not q['path'].startswith('/') or q['path'].startswith('//'): raise RuntimeError('Refused')
c=http.client.HTTPConnection('127.0.0.1',8088,timeout=90)
c.request(q['method'],q['path'],base64.b64decode(q['body']),q['headers'])
r=c.getresponse(); body=r.read(20000001)
if len(body)>20000000: raise RuntimeError('Response too large')
print(json.dumps({'status':r.status,'headers':r.getheaders(),'body':base64.b64encode(body).decode()}))
c.close()
'''


def valid_request(method, path, host, origin, referer):
    if method not in ('GET', 'POST', 'HEAD') or host != '127.0.0.1:8088':
        return False
    parsed = urlsplit(path)
    if not path.startswith('/') or path.startswith('//') or parsed.scheme or parsed.netloc or '\\' in path or any(ord(c)<32 for c in path):
        return False
    for source in (origin, referer):
        if source:
            value = urlsplit(source)
            if value.scheme != 'http' or value.netloc != host or value.username or value.password:
                return False
    return True


class Relay(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    slots = threading.BoundedSemaphore(4)
    container = None
    lab_id = None

    def log_message(self, *args):
        pass

    def do_GET(self):
        self.forward()

    def do_POST(self):
        self.forward()

    def do_HEAD(self):
        self.forward()

    def forward(self):
        self.connection.settimeout(100)
        if self.client_address[0] != '127.0.0.1' or not valid_request(self.command, self.path, self.headers.get('Host'), self.headers.get('Origin'), self.headers.get('Referer')):
            self.send_error(403)
            return
        lengths = self.headers.get_all('Content-Length') or ['0']
        if len(lengths) != 1 or not lengths[0].isdigit() or int(lengths[0]) > MAX_BODY or self.headers.get('Transfer-Encoding'):
            self.send_error(413)
            return
        body = self.rfile.read(int(lengths[0]))
        if len(body) != int(lengths[0]):
            self.send_error(400)
            return
        headers = {k: v for k, v in self.headers.items() if k.lower() not in (
            'connection', 'proxy-connection', 'proxy-authorization', 'transfer-encoding', 'upgrade', 'content-length', 'accept-encoding')}
        headers['Connection'] = 'close'
        headers['Accept-Encoding'] = 'identity'
        query = json.dumps({'method': self.command, 'path': self.path, 'headers': headers, 'body': base64.b64encode(body).decode()}).encode()
        if not self.slots.acquire(timeout=30):
            self.send_error(503)
            return
        try:
            raw = instance_tools.docker('exec', '-i', self.container, '/opt/fse/venv/bin/python', '-c', REMOTE, input=query, timeout=100)
            response = json.loads(raw)
            entries = response['headers']
            values = {k.lower(): v for k, v in entries}
            if not self.path.startswith('/public/') and response['status'] not in (301,302,303,404):
                if values.get('x-fse-test-environment') != 'synthetic-only' or values.get('x-fse-lab-id') != self.lab_id:
                    raise ValueError('Wrong response identity')
            if 'location' in values:
                location = values['location']
                parsed = urlsplit(location)
                if parsed.scheme or parsed.netloc:
                    if parsed.scheme != 'http' or parsed.netloc != '127.0.0.1:8088' or parsed.username or parsed.password:
                        raise ValueError('External redirect refused')
                elif not location.startswith('/') or location.startswith('//'):
                    raise ValueError('Ambiguous redirect refused')
            data = base64.b64decode(response['body'], validate=True)
            self.send_response_only(response['status'])
            for key, value in entries:
                if key.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Connection', 'close')
            self.end_headers()
            if self.command != 'HEAD':
                self.wfile.write(data)
        except Exception:
            self.send_error(502, 'Synthetic lab relay unavailable')
        finally:
            self.slots.release()
            self.close_connection = True


def main():
    path, info = instance_tools.instance(sys.argv[1])
    app = instance_tools.inspect(path, 'app', True)
    Relay.container = app['Id']
    Relay.lab_id = path.name
    with ThreadingHTTPServer(('127.0.0.1', 8088), Relay) as server:
        print('Synthetic Linux UI relay ready on Windows loopback 8088; no Docker ports published.', flush=True)
        server.serve_forever()


if __name__ == '__main__':
    main()
