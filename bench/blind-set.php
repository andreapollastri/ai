<?php

/*
|--------------------------------------------------------------------------
| Blind set: 10 unseen situations with a human reference answer
|--------------------------------------------------------------------------
|
| Every situation here is new: none of them appear in george-jev-bench.php,
| in the UI examples, or in any prompt used while tuning the reasoner.
|
| `human` is written BEFORE running any model, and is not edited afterwards.
| It is one operator's reading, not ground truth, so each entry carries the
| reasoning that produced it and a `hard` flag when a careful person could
| land on the other side.
|
| The set is deliberately adversarial for lexical models: in most cases the
| tone of the text points one way and the operational facts point the other.
|
*/

return [

    [
        'id' => 'silent-churn',
        'note' => 'Quiet text, loud commercial fact',
        'situation' => 'Account review for Northwind Ltd, our second largest customer: daily active users dropped from 340 to 11 over the last six weeks, no support tickets, no complaints. Their renewal is in 19 days. The champion who bought the product left the company in August.',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Act this week', 'prompt' => 'Does this need an owner assigned this week?', 'yes' => 'Something is at stake that gets harder to fix by waiting', 'no' => 'It can wait for the normal review cycle'],
            ['type' => 'score', 'name' => 'Risk', 'prompt' => 'How much revenue risk does this carry?', 'levels' => ['None', 'Low', 'Serious', 'Severe']],
        ],
        'human' => [
            'Act this week' => ['answer' => 'yes', 'confidence' => 0.95, 'why' => 'Usage collapsed and the buyer is gone with 19 days to renewal. Every day of delay removes room to rescue it.'],
            'Risk' => ['answer' => 3.0, 'why' => 'Second largest customer, near-zero usage, no internal sponsor. Severe is defensible too, so I sit between Serious and Severe.', 'hard' => true],
        ],
    ],

    [
        'id' => 'angry-but-wrong',
        'note' => 'Furious tone, customer is factually in the wrong',
        'situation' => 'ARE YOU KIDDING ME. The jacket is a size Small and I am obviously not a Small. Absolute joke of a company. I want a full refund and compensation for my wasted evening. (Order shows size Small selected at checkout, delivered on time, worn once, outside the 14-day window by two months.)',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Is urgent', 'prompt' => 'Does this message convey urgency?', 'yes' => 'Explicitly time-sensitive or escalating', 'no' => 'No time pressure expressed'],
            ['type' => 'score', 'name' => 'Goodwill', 'prompt' => 'How much goodwill does resolving this need?', 'levels' => ['None, policy answer', 'Small gesture', 'Full refund', 'Refund and apology']],
        ],
        'human' => [
            'Is urgent' => ['answer' => 'no', 'confidence' => 0.6, 'why' => 'Loud, but nothing is time-sensitive: no deadline, no escalation path, no worsening harm. Anger is not urgency. A reasonable operator could read the escalation risk as urgency, so this is genuinely contestable.', 'hard' => true],
            'Goodwill' => ['answer' => 0.5, 'why' => 'Customer error, worn, two months outside policy. A polite policy answer, at most a token gesture to protect the relationship.'],
        ],
    ],

    [
        'id' => 'calm-security',
        'note' => 'Polite tone, maximum operational severity',
        'situation' => 'Hi, hope you are well. I was poking at your API and noticed that changing the account_id in the /v2/invoices call returns other customers\' invoices without any error. Happy to share details. No rush, let me know where to send them.',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Page someone', 'prompt' => 'Should this wake an on call engineer right now?', 'yes' => 'Revenue affecting and still getting worse', 'no' => 'Contained, or already recovering on its own'],
            ['type' => 'choice', 'name' => 'Team', 'prompt' => 'Which team should handle this?', 'options' => [
                ['label' => 'support', 'applies' => 'General questions and follow ups'],
                ['label' => 'security', 'applies' => 'Vulnerabilities, data exposure and abuse'],
                ['label' => 'billing', 'applies' => 'Refunds, payments and invoices'],
            ]],
        ],
        'human' => [
            'Page someone' => ['answer' => 'yes', 'confidence' => 0.85, 'why' => 'Live cross-tenant data exposure. The criteria say "revenue affecting", which does not literally fit, but no sensible operator leaves an open IDOR until morning.', 'hard' => true],
            'Team' => ['answer' => 'security', 'confidence' => 0.98, 'why' => 'The word "invoices" is a decoy. This is unauthorised access to other tenants\' data.'],
        ],
    ],

    [
        'id' => 'vendor-invoice',
        'note' => 'Reads like routine billing, is a payment fraud attempt',
        'situation' => 'Email from our print supplier\'s usual contact: "Please note our bank details have changed, new IBAN below, kindly process the outstanding 48,000 EUR invoice today so we can close our quarter." The reply-to address is the supplier name with an extra hyphen, and the invoice PDF has last quarter\'s reference number.',
        'conditions' => [
            ['type' => 'choice', 'name' => 'Team', 'prompt' => 'Which team should handle this?', 'options' => [
                ['label' => 'billing', 'applies' => 'Refunds, payments and invoices'],
                ['label' => 'security', 'applies' => 'Vulnerabilities, fraud and abuse'],
                ['label' => 'support', 'applies' => 'General questions and follow ups'],
            ]],
            ['type' => 'noul', 'name' => 'Pay today', 'prompt' => 'Should the payment be made today as requested?', 'yes' => 'A legitimate request that should be processed', 'no' => 'Something must be verified before any money moves'],
        ],
        'human' => [
            'Team' => ['answer' => 'security', 'confidence' => 0.9, 'why' => 'Changed bank details plus a lookalike domain plus time pressure is textbook supplier invoice fraud. Billing executes, security investigates.'],
            'Pay today' => ['answer' => 'no', 'confidence' => 0.99, 'why' => 'Never move 48k on an emailed IBAN change. Verify out of band first.'],
        ],
    ],

    [
        'id' => 'cert-expiry',
        'note' => 'Nothing broken yet, breaks predictably',
        'situation' => 'Saturday 22:40. Monitoring: the TLS certificate for api.production expires in 5 hours 20 minutes. Auto-renewal has failed three times with a DNS validation error. Everything is currently green and serving normally.',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Page someone', 'prompt' => 'Should this wake an on call engineer right now?', 'yes' => 'Revenue affecting and still getting worse', 'no' => 'Contained, or already recovering on its own'],
            ['type' => 'score', 'name' => 'Severity', 'prompt' => 'How severe is this incident?', 'levels' => ['Negligible', 'Minor', 'Major', 'Critical']],
        ],
        'human' => [
            'Page someone' => ['answer' => 'yes', 'confidence' => 0.9, 'why' => 'A total API outage is scheduled for 04:00 and the automated fix has already failed three times. Waiting means it breaks while everyone sleeps.'],
            'Severity' => ['answer' => 2.4, 'why' => 'Not yet impacting, certain to be Critical in five hours. Between Major and Critical.', 'hard' => true],
        ],
    ],

    [
        'id' => 'loud-typo',
        'note' => 'Maximum urgency words, trivial fact',
        'situation' => 'URGENT!!! CRITICAL ISSUE!!! Your pricing page says "Choose you plan" instead of "Choose your plan". This is EXTREMELY unprofessional and I need it fixed IMMEDIATELY. I am a paying customer!!!',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Page someone', 'prompt' => 'Should this wake an on call engineer right now?', 'yes' => 'Revenue affecting and still getting worse', 'no' => 'Contained, or already recovering on its own'],
            ['type' => 'score', 'name' => 'Severity', 'prompt' => 'How severe is this issue?', 'levels' => ['Negligible', 'Minor', 'Major', 'Critical']],
        ],
        'human' => [
            'Page someone' => ['answer' => 'no', 'confidence' => 0.99, 'why' => 'A typo. Nobody wakes for a missing letter, whatever the capitalisation.'],
            'Severity' => ['answer' => 0.2, 'why' => 'Cosmetic copy fix, Negligible.'],
        ],
    ],

    [
        'id' => 'planned-load-test',
        'note' => 'Alert that looks like an incident, announced in advance',
        'situation' => 'Alert: checkout latency p99 at 4.1 seconds, up from 380ms, error rate 2.2%. The engineering channel has a pinned message from this morning: "Load test against the checkout path 20:00-22:00 today, expect latency and synthetic errors, do not page." Current time is 21:10.',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Page someone', 'prompt' => 'Should this wake an on call engineer right now?', 'yes' => 'Revenue affecting and still getting worse', 'no' => 'Contained, or already recovering on its own'],
            ['type' => 'noul', 'name' => 'Real incident', 'prompt' => 'Is this a real customer-facing incident?', 'yes' => 'Genuine unplanned degradation affecting users', 'no' => 'Expected behaviour from planned work'],
        ],
        'human' => [
            'Page someone' => ['answer' => 'no', 'confidence' => 0.85, 'why' => 'Announced load test, inside the stated window, explicit do-not-page instruction.'],
            'Real incident' => ['answer' => 'no', 'confidence' => 0.9, 'why' => 'Expected output of planned work. Real customers may see it, but it is not unplanned.'],
        ],
    ],

    [
        'id' => 'quiet-legal-clock',
        'note' => 'No threat words, hard legal deadline',
        'situation' => 'Hello, I would like all my personal data deleted from your systems, and a copy of what you hold, under my rights as an EU resident. Thanks in advance for your help. Have a nice day.',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Escalate legal', 'prompt' => 'Should this go to legal or a specialist queue?', 'yes' => 'A legal, regulatory, or reputational threat', 'no' => 'An ordinary customer complaint'],
            ['type' => 'noul', 'name' => 'Has deadline', 'prompt' => 'Does this carry a deadline we are obliged to meet?', 'yes' => 'A fixed window applies, whether or not it is stated', 'no' => 'No binding timeline'],
        ],
        'human' => [
            'Escalate legal' => ['answer' => 'yes', 'confidence' => 0.8, 'why' => 'A GDPR erasure and access request is a regulated process with a statutory clock. Not a threat, but definitely a specialist queue. The stated yes-criterion says "threat", which fits badly, so a literal reader could say no.', 'hard' => true],
            'Has deadline' => ['answer' => 'yes', 'confidence' => 0.95, 'why' => 'One month under GDPR, whether or not the sender mentions it.'],
        ],
    ],

    [
        'id' => 'reopened-ticket',
        'note' => 'New information changes the severity of an old ticket',
        'situation' => 'Ticket #4471, closed last week as "user error", reopened by the customer: "Same problem again. I checked with two colleagues in other companies who use your app and both see it too. Our accountant says the VAT total on the exported report is wrong by 22% on every invoice since the March update."',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Reopen as bug', 'prompt' => 'Should this be treated as a real product bug?', 'yes' => 'Evidence points to a defect on our side', 'no' => 'Most likely a configuration or user mistake'],
            ['type' => 'score', 'name' => 'Severity', 'prompt' => 'How severe is this issue?', 'levels' => ['Negligible', 'Minor', 'Major', 'Critical']],
        ],
        'human' => [
            'Reopen as bug' => ['answer' => 'yes', 'confidence' => 0.95, 'why' => 'Reproduced across three independent companies and correlated with a release date. That is a defect, not user error.'],
            'Severity' => ['answer' => 2.6, 'why' => 'Wrong tax figures on every invoice since March, across customers. Major heading to Critical given the financial and compliance exposure.', 'hard' => true],
        ],
    ],

    [
        'id' => 'overqualified-risk',
        'note' => 'Strong on paper, real question is retention not capability',
        'situation' => 'Applicant for a mid-level support engineer role: former VP of Engineering at a 200-person company, 18 years experience, says they want "something calmer with less responsibility", accepts the posted salary which is a third of their last one, would report to someone with 4 years experience.',
        'conditions' => [
            ['type' => 'noul', 'name' => 'Interview', 'prompt' => 'Should this application go to a first interview?', 'yes' => 'Worth an hour of the team\'s time to explore', 'no' => 'Clearly not a fit, decline now'],
            ['type' => 'choice', 'name' => 'Main risk', 'prompt' => 'What is the main risk with this hire?', 'options' => [
                ['label' => 'capability', 'applies' => 'Cannot do the work required'],
                ['label' => 'retention', 'applies' => 'Can do the work but is likely to leave or be unhappy'],
                ['label' => 'cost', 'applies' => 'Too expensive for the budget'],
            ]],
        ],
        'human' => [
            'Interview' => ['answer' => 'yes', 'confidence' => 0.85, 'why' => 'The stated motivation is coherent and they accepted the band. One hour to test whether the motivation is real is cheap.'],
            'Main risk' => ['answer' => 'retention', 'confidence' => 0.95, 'why' => 'Capability is obviously not the issue and they accepted the salary. The risk is boredom and a short tenure.'],
        ],
    ],

];
