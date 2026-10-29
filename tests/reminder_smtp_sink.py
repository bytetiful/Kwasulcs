"""Loopback-only SMTP test sink. Never relays mail or contacts external hosts."""
import json
import socketserver
import sys
import threading
import time

attempts = {}
lock = threading.Lock()

class SMTP(socketserver.StreamRequestHandler):
    def reply(self, text):
        self.wfile.write((text + '\r\n').encode())
        self.wfile.flush()

    def handle(self):
        self.reply('220 local-reminder-qa ESMTP')
        recipient = ''
        while True:
            raw = self.rfile.readline()
            if not raw:
                break
            line = raw.decode(errors='replace').strip()
            command = line.split(' ', 1)[0].upper()
            if command in ('EHLO', 'HELO'):
                self.reply('250 local-reminder-qa')
            elif command == 'RCPT':
                recipient = line.split(':', 1)[1].strip('<> ')
                with lock:
                    attempts[recipient] = attempts.get(recipient, 0) + 1
                    attempt = attempts[recipient]
                if 'permanent' in recipient or ('retry' in recipient and attempt == 1):
                    self.reply('451 Simulated QA transport failure')
                else:
                    self.reply('250 Recipient accepted')
            elif command == 'DATA':
                self.reply('354 End with dot')
                while self.rfile.readline() not in (b'.\r\n', b'', b'.\n'):
                    pass
                if 'slow' in recipient:
                    time.sleep(2)
                print(json.dumps({'accepted': recipient}), flush=True)
                self.reply('250 Queued by local sink only')
            elif command == 'QUIT':
                self.reply('221 Bye')
                break
            else:
                self.reply('250 OK')

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

with Server(('127.0.0.1', int(sys.argv[1])), SMTP) as server:
    print('READY', flush=True)
    server.serve_forever()
