#!/usr/bin/env python3
"""HTTPS simulator for Namingo's subscription provisioning contract. DOES NOT implement ACME."""
# Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0
import argparse
import base64
import datetime as dt
import hashlib
import hmac
import json
import os
import re
import secrets
import sqlite3
import ssl
from http.server import BaseHTTPRequestHandler, HTTPServer


def create_server(host, port, database, token, cert, key, fail_once=False):
    if len(token) < 24:
        raise ValueError('Set a simulator token of at least 24 characters')
    connection = sqlite3.connect(database)
    connection.executescript('CREATE TABLE IF NOT EXISTS subscriptions (key TEXT PRIMARY KEY, revision INTEGER, request TEXT, response TEXT); CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);')
    connection.commit()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def reply(self, status, value):
            body = json.dumps(value).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Cache-Control', 'no-store')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            self.reply(200 if self.path == '/health' else 404, {'simulation': True, 'acme_supported': False})

        def do_PUT(self):
            if not hmac.compare_digest(self.headers.get('Authorization', ''), 'Bearer ' + token):
                self.reply(401, {'error': 'unauthorized'})
                return
            match = re.fullmatch(r'/v1/subscriptions/([0-9a-f]{32})', self.path)
            if not match:
                self.reply(404, {'error': 'not_found'})
                return
            try:
                length = int(self.headers.get('Content-Length', '0'))
                if not 0 < length <= 16384:
                    raise ValueError()
                request = json.loads(self.rfile.read(length))
                key_id = match[1]
                if request.get('schema_version') != 1 or request.get('subscription_key') != key_id:
                    raise ValueError()
                operation = request['operation_id']
                if not re.fullmatch('[0-9a-f]{32}', operation) or self.headers.get('Idempotency-Key') != operation:
                    raise ValueError()
                if request['state'] not in ['active', 'suspended', 'canceled', 'expired', 'deleted']:
                    raise ValueError()
                domains = request['domains']
                if not isinstance(domains, list) or not 1 <= len(domains) <= 2 or len(set(domains)) != len(domains):
                    raise ValueError()
                for domain in domains:
                    if not isinstance(domain, str) or not re.fullmatch(r'(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)+', domain):
                        raise ValueError()
                expiry = request['expires_at']
                if request['state'] == 'active' and expiry is None:
                    raise ValueError()
                if expiry is not None:
                    dt.datetime.strptime(expiry, '%Y-%m-%dT%H:%M:%SZ')
                revision = request['revision']
                if type(revision) is not int or revision < 1:
                    raise ValueError()
            except (ValueError, KeyError, TypeError):
                self.reply(400, {'error': 'invalid_request'})
                return
            canonical = json.dumps(request, sort_keys=True, separators=(',', ':'))
            row = connection.execute('SELECT revision, request, response FROM subscriptions WHERE key=?', (key_id,)).fetchone()
            if row and revision <= row[0]:
                if revision == row[0] and canonical == row[1]:
                    self.reply(200, json.loads(row[2]))
                else:
                    self.reply(409, {'error': 'revision_conflict'})
                return
            old = json.loads(row[2]) if row else {}
            response = {'schema_version': 1, 'subscription_id': 'sim-' + key_id,
                        'domains': domains, 'state': request['state'], 'expires_at': expiry,
                        'directory_url': 'https://acme.emulator.invalid/directory', 'client_ready': False,
                        'eab': old.get('eab') or {'kid': 'sim-' + key_id, 'hmac_key': base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip('=')}}
            connection.execute('INSERT INTO subscriptions (key,revision,request,response) VALUES (?,?,?,?) ON CONFLICT(key) DO UPDATE SET revision=excluded.revision, request=excluded.request, response=excluded.response',
                               (key_id, revision, canonical, json.dumps(response)))
            connection.commit()
            # Exercise the case where the issuer applied a mutation but the response was lost.
            if fail_once and not connection.execute("SELECT 1 FROM meta WHERE key='failed_once'").fetchone():
                connection.execute("INSERT INTO meta VALUES ('failed_once','yes')")
                connection.commit()
                self.reply(503, {'error': 'simulated_lost_confirmation'})
                return
            self.reply(200, response)

    server = HTTPServer((host, port), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    return server


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=9443)
    parser.add_argument('--database', required=True)
    parser.add_argument('--cert', required=True)
    parser.add_argument('--key', required=True)
    parser.add_argument('--fail-once', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    server = create_server(args.host, args.port, args.database, os.environ.get('NAMINGO_SIMULATOR_TOKEN', ''), args.cert, args.key, args.fail_once)
    print('Management simulator ready; NO ACME or certificate issuance', flush=True)
    server.serve_forever()
