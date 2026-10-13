#!/usr/bin/env python3
"""Opt-in staging checks. Use a dedicated verified User with current policy grants.
No real account credentials are printed. Creates and then cancels one synthetic Case.
Requires CASE_TEST_BASE_URL (ending in /api), CASE_TEST_USER_TOKEN, CASE_TEST_COUNTRY,
CASE_TEST_CATALOG_ENTRY_ID, CASE_TEST_CATALOG_VERSION, CASE_TEST_DELIVERY_MODE,
CASE_TEST_JURISDICTIONS_JSON and CASE_TEST_ANSWERS_JSON. Provision a dedicated enabled
policy and eligible expert coverage first; no documents required by this synthetic policy.
"""
import concurrent.futures
import json
import os
import urllib.error
import urllib.request
import urllib.parse
import uuid
import threading


def main():
    base = os.environ['CASE_TEST_BASE_URL'].rstrip('/')
    parsed = urllib.parse.urlparse(base)
    if parsed.scheme != 'https' and parsed.hostname not in ('localhost', '127.0.0.1'):
        raise ValueError('Use HTTPS for remote test environments.')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    opener = urllib.request.build_opener(NoRedirect)
    token = os.environ['CASE_TEST_USER_TOKEN']
    country = os.environ['CASE_TEST_COUNTRY']
    result = {'environment': 'operator-selected', 'synthetic': True, 'checks': []}

    def call(method, path, body=None, key=None):
        headers = {'Authorization': 'Bearer ' + token, 'Accept': 'application/json', 'Content-Type': 'application/json'}
        if key:
            headers['Idempotency-Key'] = key
        req = urllib.request.Request(base + path, data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
        try:
            with opener.open(req, timeout=45) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read())

    def pair(tasks):
        barrier = threading.Barrier(len(tasks))
        def synchronized(task):
            barrier.wait(timeout=10)
            return call(*task)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
            futures = [executor.submit(synchronized, task) for task in tasks]
            return [f.result() for f in futures]

    payload = {'title': 'Synthetic concurrency check', 'problemDescription': 'Test the software API intake concurrency.', 'desiredOutcome': 'One committed transition.', 'caseCountry': country, 'language': 'en', 'urgency': 'normal', 'subjectType': 'self', 'privacyChoice': 'private', 'answers': {'immediateDanger': False, 'requiresInPerson': False, 'ambiguousHighRisk': False}}
    identifier = None
    try:
        key = str(uuid.uuid4())
        created = pair([('POST', '/v2/user/cases', payload, key)] * 2)
        assert [x[0] for x in created] == [201, 201], 'Concurrent create failed.'
        ids = [x[1]['data']['item']['id'] for x in created]
        assert ids[0] == ids[1], 'Duplicate Case created.'
        identifier = ids[0]
        path = '/v2/user/cases/' + str(identifier)
        result['checks'].append('same-key create replay passed')
        updated = pair([('PATCH', path, {'expectedVersion': 1, 'title': title}, str(uuid.uuid4())) for title in ('Synthetic concurrent A', 'Synthetic concurrent B')])
        assert sorted(x[0] for x in updated) == [200, 409], 'Expected one autosave winner.'
        result['checks'].append('competing autosave passed')
        status, current = call('GET', path)
        assert status == 200
        version = current['data']['item']['version']
        selection = {'catalogEntryId': int(os.environ['CASE_TEST_CATALOG_ENTRY_ID']),
                     'catalogVersion': int(os.environ['CASE_TEST_CATALOG_VERSION']),
                     'deliveryMode': os.environ['CASE_TEST_DELIVERY_MODE'],
                     'jurisdictionCodes': json.loads(os.environ['CASE_TEST_JURISDICTIONS_JSON']),
                     'answers': json.loads(os.environ['CASE_TEST_ANSWERS_JSON']), 'confirmed': True}
        status, selected = call('PUT', path + '/scopes', {'expectedVersion': version, 'scopes': [selection]}, str(uuid.uuid4()))
        assert status == 200, 'Provision a current catalog policy and valid synthetic answers first.'
        status, readiness = call('GET', path + '/readiness')
        assert status == 200 and readiness['data']['item']['status'] == 'ready_for_matching', 'Provision eligible supply and consent first.'
        status, confirmed = call('POST', path + '/confirm', {'expectedVersion': selected['data']['item']['version'], 'confirmed': True}, str(uuid.uuid4()))
        assert status == 200
        version = confirmed['data']['item']['version']
        submitted = pair([('POST', path + '/submit', {'expectedVersion': version}, str(uuid.uuid4())) for _ in range(2)])
        assert sorted(x[0] for x in submitted) == [200, 409], 'Expected one submit winner.'
        result['checks'].append('competing submit passed')
        result['passed'] = True
    finally:
        if identifier is not None:
            status, current = call('GET', '/v2/user/cases/' + str(identifier))
            if status == 200 and current['data']['item']['status'] != 'cancelled':
                status, _ = call('POST', '/v2/user/cases/' + str(identifier) + '/cancel', {'expectedVersion': current['data']['item']['version']}, str(uuid.uuid4()))
                result['syntheticCaseCancelled'] = status == 200
        print(json.dumps(result, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Do not echo HTTP request payloads, tokens, raw network exceptions, or URLs.
        print(json.dumps({'passed': False, 'errorType': type(error).__name__}))
        raise SystemExit(1)
