const express = require('express');
const { v4: uuidv4 } = require('uuid');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3001;
const INTERNAL_SECRET = process.env.INTERNAL_SECRET || '';

function verifySecret(req, res, next) {
    const secret = req.headers['x-internal-secret'];
    if (INTERNAL_SECRET && secret !== INTERNAL_SECRET) {
        return res.status(403).json({ error: 'Unauthorized' });
    }
    next();
}

app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'wa-gateway',
        timestamp: Date.now(),
    });
});

app.post('/sessions/start', verifySecret, (req, res) => {
    const { wa_account_id } = req.body;
    res.json({
        status: 'pending',
        session_id: wa_account_id,
        message: 'QR code generation started',
    });
});

app.get('/sessions/:id/status', verifySecret, (req, res) => {
    res.json({
        status: 'disconnected',
        wa_account_id: req.params.id,
        phone: null,
        connected_at: null,
    });
});

app.post('/messages/send', verifySecret, (req, res) => {
    res.json({
        queued: true,
        message_id: uuidv4(),
        wa_account_id: req.body.wa_account_id,
        to_phone: req.body.to_phone,
    });
});

app.listen(PORT, () => {
    console.log(`WA Gateway running on port ${PORT}`);
});
