<?php

it('registers the coolify-design skill in boost.json', function (): void {
    $boost = json_decode((string) file_get_contents(base_path('boost.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($boost['skills'] ?? [])->toContain('coolify-design');
});

it('ships a coolify-design skill with required frontmatter and references', function (): void {
    $skillPath = base_path('.agents/skills/coolify-design/SKILL.md');

    expect($skillPath)->toBeFile();

    $contents = (string) file_get_contents($skillPath);

    expect($contents)->toStartWith("---\n")
        ->and($contents)->toContain('name: coolify-design')
        ->and($contents)->toContain('description:')
        ->and(base_path('.agents/skills/coolify-design/references/tokens.md'))->toBeFile()
        ->and(base_path('.agents/skills/coolify-design/references/components.md'))->toBeFile()
        ->and(base_path('.agents/skills/coolify-design/references/checklist.md'))->toBeFile();
});

it('exposes coolify-design to cursor and claude skill paths', function (): void {
    expect(base_path('.cursor/skills/coolify-design/SKILL.md'))->toBeFile()
        ->and(base_path('.claude/skills/coolify-design/SKILL.md'))->toBeFile();
});
