'use strict';

const express = require('express');
const { v4: uuidv4 } = require('uuid');

const app = express();
app.use(express.json({ limit: '10mb' }));

const PORT = process.env.PORT || 3001;
const INTERNAL_SECRET = process.env.INTERNAL_SECRET || '';

// Lazy-load sessionManager so startup doesn't crash if Baileys not yet installed
let sessionManager = null;
function getSessionManager() {
    if (!sessionManager) {
        sessionManager = require('./src/sessionManager');
    }
    return sessionManager;
}

function verifySecret(req, res, next) {
    const secret = req.headers['x-internal-secret'];
    if (INTERNAL_SECRET && secret !== INTERNAL_SECRET) {
        return res.status(403).json({ error: 'Unauthorized' });
    }
    next();
}

// ── Health ────────────────────────────────────────────────────────────────────

app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'wa-gateway',
        timestamp: Date.now(),
    });
});

// ── Session management ────────────────────────────────────────────────────────

// POST /sessions/start — initiate Baileys session for a WA account
app.post('/sessions/start', verifySecret, async (req, res) => {
    const { wa_account_id, callback_url, internal_secret } = req.body;
    if (!wa_account_id) {
        return res.status(422).json({ error: 'Missing required field: wa_account_id' });
    }

    try {
        const sm = getSessionManager();
        const result = await sm.startSession(
            wa_account_id,
            callback_url || null,
            internal_secret || INTERNAL_SECRET,
        );
        res.json(result);
    } catch (err) {
        console.error('[wa-gateway] startSession error:', err.message);
        res.status(500).json({ error: 'Failed to start session', detail: err.message });
    }
});

// POST /sessions/stop — stop and logout a session
app.post('/sessions/stop', verifySecret, async (req, res) => {
    const { wa_account_id } = req.body;
    if (!wa_account_id) {
        return res.status(422).json({ error: 'Missing required field: wa_account_id' });
    }
    try {
        const sm = getSessionManager();
        const stopped = await sm.stopSession(wa_account_id);
        res.json({ success: stopped, account_id: wa_account_id });
    } catch (err) {
        console.error('[wa-gateway] stopSession error:', err.message);
        res.status(500).json({ error: 'Failed to stop session', detail: err.message });
    }
});

// GET /sessions/:id/status — legacy status endpoint (stub-compatible)
app.get('/sessions/:id/status', verifySecret, (req, res) => {
    try {
        const sm = getSessionManager();
        const status = sm.getStatus(req.params.id);
        res.json({
            wa_account_id: req.params.id,
            ...status,
        });
    } catch (err) {
        res.json({
            status: 'disconnected',
            wa_account_id: req.params.id,
            phone: null,
            connected_at: null,
        });
    }
});

// POST /messages/send — legacy send endpoint
app.post('/messages/send', verifySecret, async (req, res) => {
    const { wa_account_id, to_phone, body } = req.body;
    try {
        const sm = getSessionManager();
        const result = await sm.sendMessage(wa_account_id, to_phone, body);
        res.json({
            queued: result.success,
            message_id: result.provider_message_id || uuidv4(),
            wa_account_id,
            to_phone,
            ...(!result.success && { error: result.error }),
        });
    } catch (err) {
        res.json({ queued: false, error: err.message });
    }
});

// ── PRINSIP 13 — Contract: POST /dispatch (Laravel → WA) ─────────────────────
app.post('/dispatch', verifySecret, async (req, res) => {
    const {
        wa_account_id,
        to_phone,
        message_type,
        body,
        file_url,
        media_url,
        mimetype,
        file_name,
    } = req.body;

    if (!wa_account_id || !to_phone || !message_type) {
        return res.status(422).json({
            error: 'Missing required fields: wa_account_id, to_phone, message_type',
        });
    }

    const isDocument = message_type === 'document' || message_type === 'file';

    if (isDocument) {
        const url = file_url || media_url;
        if (!url) {
            return res.status(422).json({
                error: 'Missing file_url (or media_url) for document message_type',
            });
        }

        try {
            const sm = getSessionManager();
            const result = await sm.sendDocument(
                wa_account_id,
                to_phone,
                url,
                body || '',
                mimetype || 'application/pdf',
                file_name || null,
            );
            return res.json({
                success: result.success,
                provider_message_id: result.provider_message_id || null,
                ...(result.error && { error: result.error }),
            });
        } catch (err) {
            console.error('[wa-gateway] dispatch document error:', err.message);
            return res.json({ success: false, error: err.message });
        }
    }

    if (body === undefined) {
        return res.status(422).json({
            error: 'Missing body for text message_type',
        });
    }

    try {
        const sm = getSessionManager();
        const result = await sm.sendMessage(wa_account_id, to_phone, body);
        return res.json({
            success: result.success,
            provider_message_id: result.provider_message_id || null,
            ...(result.error && { error: result.error }),
        });
    } catch (err) {
        console.error('[wa-gateway] dispatch error:', err.message);
        return res.json({ success: false, error: err.message });
    }
});

// ── PRINSIP 13 — Contract: GET /status/:wa_account_id (Laravel → WA) ─────────
app.get('/status/:wa_account_id', verifySecret, (req, res) => {
    try {
        const sm = getSessionManager();
        const status = sm.getStatus(req.params.wa_account_id);
        res.json(status);
    } catch (err) {
        res.json({ status: 'disconnected', phone: null, connected_at: null });
    }
});

// ── Debug endpoint — simulate inbound message (dev only) ─────────────────────
if (process.env.NODE_ENV !== 'production') {
    app.post('/webhook/test', verifySecret, async (req, res) => {
        const axios = require('axios');
        const laravelUrl = process.env.LARAVEL_URL || 'http://app';
        const payload = {
            wa_account_id: req.body.wa_account_id || 'test-account',
            provider_message_id: uuidv4(),
            from_phone: req.body.from_phone || '+628111000001',
            message_type: req.body.message_type || 'text',
            body: req.body.body || 'test message',
            media_url: null,
            raw_payload: req.body,
            received_at: new Date().toISOString(),
        };
        try {
            const response = await axios.post(`${laravelUrl}/webhook/inbound`, payload, {
                headers: {
                    'X-Internal-Secret': INTERNAL_SECRET,
                    'Content-Type': 'application/json',
                },
                timeout: 10000,
            });
            res.json({ forwarded: true, laravel_response: response.data, payload });
        } catch (err) {
            res.status(500).json({ forwarded: false, error: err.message });
        }
    });
}

app.listen(PORT, () => {
    console.log(`WA Gateway running on port ${PORT}`);
});
