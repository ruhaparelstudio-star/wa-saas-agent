<?php

namespace App\Modules\QualityGuard\DTOs;

readonly class LlmGradeDTO
{
    public function __construct(
        public float $relevance,
        public float $groundedness,
        public float $tone,
        public float $safety,
        public float $overall,
        public string $verdict,
        public array $reasons,
        public array $raw,
    ) {}

    public static function from(array $data): static
    {
        $rel = self::pluckScore($data, 'relevance');
        $gnd = self::pluckScore($data, 'groundedness');
        $ton = self::pluckScore($data, 'tone');
        $saf = self::pluckScore($data, 'safety');

        $overallNode = $data['overall'] ?? [];
        $overallScore = is_array($overallNode) && isset($overallNode['score'])
            ? (float) $overallNode['score']
            : ($rel + $gnd + $ton + $saf) / 4;
        $verdict = is_array($overallNode) ? ($overallNode['verdict'] ?? self::deriveVerdict($overallScore)) : self::deriveVerdict($overallScore);

        return new static(
            relevance: $rel,
            groundedness: $gnd,
            tone: $ton,
            safety: $saf,
            overall: round($overallScore, 2),
            verdict: $verdict,
            reasons: [
                'relevance'    => $data['relevance']['reason']    ?? '',
                'groundedness' => $data['groundedness']['reason'] ?? '',
                'tone'         => $data['tone']['reason']         ?? '',
                'safety'       => $data['safety']['reason']       ?? '',
            ],
            raw: $data,
        );
    }

    public function toArray(): array
    {
        return [
            'relevance'    => $this->relevance,
            'groundedness' => $this->groundedness,
            'tone'         => $this->tone,
            'safety'       => $this->safety,
            'overall'      => $this->overall,
            'verdict'      => $this->verdict,
            'reasons'      => $this->reasons,
            'raw'          => $this->raw,
        ];
    }

    private static function pluckScore(array $data, string $key): float
    {
        $node = $data[$key] ?? null;
        if (is_array($node) && isset($node['score'])) {
            return max(0.0, min(1.0, (float) $node['score']));
        }
        return 0.0;
    }

    private static function deriveVerdict(float $score): string
    {
        return match (true) {
            $score >= 0.8 => 'good',
            $score >= 0.6 => 'warn',
            default       => 'bad',
        };
    }
}
