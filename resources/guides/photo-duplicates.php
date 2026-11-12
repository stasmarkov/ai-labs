<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Find duplicate car photos and pick the best one',
    'summary' => 'Join near-identical photos into groups by their image vectors, ask a local vision model which photo of a group is best, and review the result by hand: the review only marks photos and never deletes anything.',
    'works' => [
        [
            'title' => 'Real photos hold no duplicates, so the lab makes some',
            'body' => 'The imported photos are all different shots, and exact copies were removed on import. To have an answer to check, one command writes degraded copies of chosen photos on the same listing: scaled down and back up, recompressed, cropped, darkened and mirrored. Each copy remembers which photo it came from and how it was changed, and earlier features never see the copies.',
        ],
        [
            'title' => 'Groups are connected components of close vectors',
            'body' => 'Every photo with a vector reads its 20 nearest neighbours, and pairs at or above the cutoff become links. Photos joined by a chain of links form one group, found with union-find. Same-listing pairs count here, because duplicates are usually re-uploads. A group above 6 photos is cut at its weakest link until every part fits.',
            'snippet' => 'App\Services\DuplicateClusterService::rebuild',
        ],
        [
            'title' => 'One call shows the model the whole group',
            'body' => 'The vision model gets every photo of a group in one request, each introduced by its id, and must answer with the id of the best photo, one to four short reasons and a problem for each other photo. The answer is checked in code: an id outside the group is rejected and the call is repeated, up to three attempts. Every attempt is a logged run.',
            'snippet' => 'App\Services\DuplicateJudgementService::judge',
        ],
        [
            'title' => 'The review page only marks',
            'body' => "\"Keep this one, remove N\" marks the kept photo and the others and stores whether the model's pick or the learner's own was kept. \"Undo\" takes the marks back. No photo row and no file is ever deleted, and a photo that is not in the group is refused.",
            'snippet' => 'App\Services\DuplicateReviewService::keep',
        ],
        [
            'title' => 'A rebuild keeps what you reviewed',
            'body' => 'Running the grouping again replaces the groups nobody reviewed. A reviewed group stays with its marks and its judgement, and its photos are left out of the new groups.',
        ],
    ],
    'failures' => [
        [
            'title' => 'The page shows no groups',
            'body' => "**Cause:** no duplicates were generated, the copies were not embedded, or the grouping was not run for the current encoder model.\n\n**Fix:** run the three commands shown on the empty page, in order.",
        ],
        [
            'title' => 'A cropped or recompressed copy is missing from its group',
            'body' => "**Cause:** cropping changes what the photo shows and heavy recompression adds blocks, so their vectors drift furthest from the original; at the calibrated cutoff 0.92 only 6 of 8 cropped and 11 of 12 recompressed copies are caught, while mirrored, darkened and downscaled copies are all caught. A flip barely moves a CLIP vector.\n\n**Fix:** read the cutoff curve in the eval. A lower cutoff catches more copies but joins different photos too; a second signal such as a perceptual hash is the usual answer.",
        ],
        [
            'title' => 'The judgement failed',
            'body' => "**Cause:** three answers in a row were invalid, for example a best id that is not in the group, or the vision model was not reachable.\n\n**Fix:** open the logged run, then queue the group again with a worker on the vision queue.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Change the vision model that picks',
            'body' => "Choose another model for the photo vision row on the settings page. The page shows the judgement of the configured model and prompt only, so judge the groups again after switching.\n\n**Check:** the summary bar counts the groups as judged once the new answers are stored.",
        ],
        [
            'title' => 'Change the cutoff',
            'body' => "Set `AI_PHOTO_DUPLICATE_CUTOFF` and run `photos:duplicates` again. Reviewed groups are kept.\n\n**Check:** the command prints the new cutoff and the number of groups.",
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows and stored judgements',
            'status' => 'logged',
            'note' => 'Every attempt to judge a group writes one `ai_runs` row, and the judgement keeps the model, the prompt version, the attempts and the time.',
        ],
        [
            'name' => 'Marks only and a checked pick',
            'status' => 'guarded',
            'note' => 'The review actions take ids, check that the photo belongs to the group and never delete a row or a file. A pick outside the group is never accepted from the model.',
        ],
        [
            'name' => 'The pick looks wrong',
            'status' => 'runbook',
            'note' => "1. Open the logged run of the judgement and read the reasons.\n2. Use Pick another to keep the photo you prefer; the page stores your choice next to the model's.\n3. Compare models in the eval before changing the prompt.",
        ],
    ],
    'numbers' => [
        ['label' => 'Cutoff chosen by the eval', 'file' => 'photo-duplicates/2026-11-12/run.json', 'path' => 'cutoff.threshold'],
        ['label' => 'Groups where gemma4:12b picked the original', 'file' => 'photo-duplicates/2026-11-12/run.json', 'path' => 'judging.models.0.picked_original.rate'],
    ],
    'experiments' => [
        [
            'title' => 'Does the model prefer the original?',
            'predictions' => [
                ['id' => 'a', 'text' => 'The model almost always picks the original, since every copy is degraded.'],
                ['id' => 'b', 'text' => 'The model picks at random.'],
                ['id' => 'c', 'text' => 'The model always picks the copy with the highest similarity.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'Open a group and compare the highlighted pick with the original tag. The eval measures how often they agree; on the measured set the model picked the original less often than a coin toss over the group would suggest, which is the point to discuss.'],
            'page' => 'ai.photos.duplicates',
            'params' => [],
        ],
    ],
    'related' => ['photos', 'similar-photos', 'providers'],
];
