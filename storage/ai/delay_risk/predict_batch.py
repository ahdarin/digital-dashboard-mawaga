import sys
import json
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