import sys
import json
import types

# Di sebagian environment Windows (proses yang di-spawn PHP tanpa window
# station penuh - kejadian saat script ini dipanggil dari request HTTP,
# beda dari dipanggil manual di terminal), import asyncio gagal dengan
# OSError WinError 10106 karena _overlapped.pyd tidak bisa WSAStartup.
# joblib cuma butuh asyncio.iscoroutine() (buat fitur Memory cache pada
# fungsi async, yang tidak pernah dipakai script batch-predict sinkron
# ini), jadi kalau asyncio asli gagal di-import, ganti dengan stub minimal
# supaya "import joblib" tidak ikut gagal.
if sys.platform == 'win32':
    try:
        import asyncio  # noqa: F401
    except OSError:
        stub = types.ModuleType('asyncio')
        stub.iscoroutine = lambda obj: False
        sys.modules['asyncio'] = stub

import joblib
import pandas as pd
import os

MODEL_PATH = os.path.join(os.path.dirname(__file__), 'delay_risk_model.pkl')

def predict(input_json):
    payload = json.loads(input_json)
    items = payload['items']

    df = pd.DataFrame(items)
    feature_cols = [
        'client_category', 'pillar', 'content_complexity',
        'workload_pic_same_week', 'current_status',
        'revision_count', 'days_in_current_status'
    ]
    X = df[feature_cols]

    model = joblib.load(MODEL_PATH)
    probabilities = model.predict_proba(X)[:, 1]

    results = []
    for i, prob in enumerate(probabilities):
        score = round(float(prob) * 100)
        if score >= 70:
            level = 'high'
        elif score >= 40:
            level = 'medium'
        else:
            level = 'low'

        results.append({
            'content_item_id': items[i]['content_item_id'],
            'risk_score': score,
            'risk_level': level,
        })

    print(json.dumps(results))

if __name__ == '__main__':
    predict(sys.stdin.read())