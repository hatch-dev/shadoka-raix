<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services\Synthetic;

/**
 * The shapes a synthetic director can take.
 *
 * Carried over from the legacy generator because the patterns are the point of
 * a demo dataset: an analytics screen showing seven identical competencies
 * demonstrates nothing. `autonomyGap` in particular is the case the whole
 * instrument exists to catch — fluent on governance, blank on what the agents
 * are allowed to do.
 *
 * Target order matches instrument position: GOV DAT EXP FAI RIS HUM AUT.
 */
final class Archetypes
{
    public const DEFINITIONS = [
        'operator' => [
            'weight'  => 0.13,
            'targets' => [3.8, 3.7, 3.6, 3.5, 3.8, 3.6, 3.4],
            'note'    => 'Does most of it as a matter of course. Agents are the last thing catching up.',
        ],
        'engaged' => [
            'weight'  => 0.24,
            'targets' => [3.1, 3.2, 2.9, 2.8, 3.1, 2.9, 2.4],
            'note'    => 'Has done most of it at least once. Little of it is habit yet.',
        ],
        'briefed' => [
            'weight'  => 0.24,
            'targets' => [2.3, 2.4, 2.2, 2.1, 2.4, 2.2, 1.9],
            'stated'  => true,
            'note'    => 'Attends everything, understands most of it, has acted on none of it. Marks cluster at Aware.',
        ],
        'autonomyGap' => [
            'weight'  => 0.16,
            'targets' => [3.4, 3.3, 3.2, 3.0, 3.3, 3.1, 1.4],
            'note'    => 'Fluent on governance, blank on what the agents are allowed to do. The case the floor rule is built for.',
        ],
        'specialist' => [
            'weight'  => 0.13,
            'targets' => [3.6, 2.1, 3.3, 1.9, 3.4, 2.3, 2.6],
            'note'    => 'Deep where the committee remit runs, unaware elsewhere.',
        ],
        'newlySeated' => [
            'weight'  => 0.10,
            'targets' => [1.6, 1.9, 1.5, 1.4, 1.8, 1.7, 1.2],
            'note'    => 'Recently appointed. Mostly unaware, and honest about it.',
        ],
    ];

    /** Per-competency shifts applied on top of an archetype's targets. */
    public const SECTOR_PRIOR = [
        'Financial Services'         => [0.3, 0.2, 0.0, 0.1, 0.3, 0.0, -0.1],
        'Healthcare & Life Sciences' => [0.1, 0.4, 0.1, 0.2, 0.3, 0.1, -0.2],
        'Technology'                 => [-0.1, 0.0, 0.1, -0.1, 0.0, 0.1, 0.5],
        'Government & Public Sector' => [0.2, 0.1, 0.2, 0.3, 0.0, 0.0, -0.5],
        'Manufacturing & Industrial' => [-0.1, -0.1, -0.2, -0.2, 0.2, -0.1, -0.2],
        'Retail & Consumer'          => [-0.1, 0.0, -0.1, -0.3, -0.1, -0.1, 0.1],
        'Energy & Utilities'         => [0.1, 0.0, -0.1, -0.1, 0.4, -0.1, -0.3],
        'Education'                  => [-0.2, 0.1, 0.2, 0.3, -0.2, 0.2, -0.3],
        'Professional Services'      => [0.0, -0.1, 0.1, 0.0, -0.1, 0.3, 0.0],
        'Other'                      => [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
    ];

    /** What each seat tends to have actually done. */
    public const ROLE_BIAS = [
        'Board Chair'                     => [0.4, 0.0, 0.1, 0.1, 0.2, 0.2, 0.0],
        'Lead Independent Director'       => [0.3, 0.0, 0.1, 0.2, 0.2, 0.1, 0.0],
        'Audit Committee Chair'           => [0.4, 0.2, 0.2, 0.0, 0.3, -0.1, -0.2],
        'Risk Committee Chair'            => [0.3, 0.1, 0.0, 0.0, 0.5, -0.1, 0.0],
        'Technology / AI Committee Chair' => [0.2, 0.4, 0.3, 0.1, 0.3, 0.2, 0.6],
        'Nominating & Governance Chair'   => [0.3, -0.2, 0.1, 0.4, -0.1, 0.2, -0.3],
        'Compensation Committee Chair'    => [0.1, -0.3, -0.1, 0.2, -0.2, 0.3, -0.4],
        'Non-Executive Director'          => [-0.1, -0.2, -0.1, -0.1, -0.1, -0.1, -0.3],
        'Executive Director'              => [0.2, 0.4, 0.2, 0.0, 0.2, 0.2, 0.3],
        'Board Observer'                  => [-0.3, -0.3, -0.3, -0.2, -0.3, -0.2, -0.4],
    ];

    public const SECTORS = [
        'Financial Services', 'Healthcare & Life Sciences', 'Technology',
        'Government & Public Sector', 'Manufacturing & Industrial', 'Retail & Consumer',
        'Energy & Utilities', 'Education', 'Professional Services', 'Other',
    ];

    public const SIZES = ['1–50', '51–250', '251–1,000', '1,001–5,000', '5,001–20,000', '20,000+'];

    public const TENURES = ['Under 1 year', '1–3 years', '4–6 years', '7–10 years', 'Over 10 years'];

    public const ORG_PREFIX = [
        'Meridian', 'Northgate', 'Apex', 'ClearPath', 'Halcyon', 'Brightwater', 'Summit',
        'Ardent', 'Kestrel', 'Lumen', 'Redwood', 'Harbor', 'Vantage', 'Pinecrest',
        'Silverline', 'Cobalt', 'Atlas', 'Beacon', 'Foxglove', 'Granite', 'Orchard',
        'Tidewater', 'Ironbridge', 'Larkspur', 'Windward',
    ];

    public const ORG_SUFFIX = [
        'Financial Services'         => ['Capital Group', 'Bancorp', 'Insurance', 'Asset Management', 'Credit Union'],
        'Healthcare & Life Sciences' => ['Health System', 'Medical Group', 'Therapeutics', 'Diagnostics', 'Care Network'],
        'Technology'                 => ['Software', 'Systems', 'Labs', 'Cloud', 'Robotics'],
        'Government & Public Sector' => ['Transit Authority', 'County Services', 'Public Trust', 'Development Agency', 'Water Authority'],
        'Manufacturing & Industrial' => ['Industries', 'Manufacturing', 'Steelworks', 'Automation', 'Components'],
        'Retail & Consumer'          => ['Retail Group', 'Brands', 'Stores', 'Consumer Goods', 'Markets'],
        'Energy & Utilities'         => ['Energy', 'Power', 'Utilities', 'Renewables', 'Grid Services'],
        'Education'                  => ['University', 'Academy Trust', 'Institute', 'College Group', 'Learning Trust'],
        'Professional Services'      => ['Partners', 'Advisory', 'Consulting', 'Accounting', 'Legal Group'],
        'Other'                      => ['Holdings', 'Group', 'Collective', 'Trust', 'Foundry'],
    ];

    public const FIRST_NAMES = [
        'Dana', 'Rahul', 'Priya', 'Tom', 'Aisha', 'Marcus', 'Elena', 'Kenji',
        'Sofia', 'Omar', 'Ingrid', 'Wei', 'Nadia', 'Liam', 'Grace',
    ];

    public const LAST_NAMES = [
        'Whitfield', 'Menon', 'Natarajan', 'Reyes', 'Bello', 'Okafor', 'Lindqvist',
        'Tanaka', 'Marchetti', 'Haddad', 'Sørensen', 'Zhang', 'Novak', 'Adeyemi', 'Quinn',
    ];

    private function __construct()
    {
    }
}
