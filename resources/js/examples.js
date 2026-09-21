/**
 * Situations you can load into the form.
 *
 * All of them are English on purpose: George's NLI heads are trained on
 * English and score it best, so the examples show the form the input should
 * take, not just what it can say.
 */
export const examples = {
    'double-charge': {
        label: 'Duplicate charge',
        situation:
            'I was charged twice for order #8831. Same amount, two minutes apart, both on the same card. I do not need a replacement, I just want the duplicate charge reversed.',
        conditions: [
            {
                type: 'choice',
                name: 'Team',
                prompt: 'Which team should handle this?',
                options: [
                    { label: 'billing', applies: 'Refunds, payments and policy exceptions' },
                    { label: 'logistics', applies: 'Damage in transit and replacements' },
                    { label: 'support', applies: 'General questions and follow ups' },
                ],
            },
            {
                type: 'noul',
                name: 'Is urgent',
                prompt: 'Does this message convey urgency?',
                yes: 'Explicitly time-sensitive or escalating',
                no: 'No time pressure expressed',
            },
        ],
    },

    'damaged-parcel': {
        label: 'Parcel arrived broken',
        situation:
            'The box arrived crushed and the ceramic vase inside is in pieces. Please send a replacement to the same address. I do not want a refund.',
        conditions: [
            {
                type: 'choice',
                name: 'Team',
                prompt: 'Which team should handle this?',
                options: [
                    { label: 'logistics', applies: 'Damage in transit and replacements' },
                    { label: 'billing', applies: 'Refunds, payments and policy exceptions' },
                    { label: 'support', applies: 'General questions and follow ups' },
                ],
            },
            {
                type: 'score',
                name: 'Goodwill',
                prompt: 'How much goodwill does resolving this need?',
                levels: ['None, policy answer', 'Small gesture', 'Full refund', 'Refund and apology'],
            },
        ],
    },

    'wedding-dress': {
        label: 'High-stakes complaint',
        situation:
            'The wedding dress arrived stained and torn two days before the ceremony. The bride is in tears. We have no backup. This ruined the week. We need this made right today.',
        conditions: [
            {
                type: 'noul',
                name: 'Is urgent',
                prompt: 'Does this message convey urgency?',
                yes: 'Explicitly time-sensitive or escalating',
                no: 'No time pressure expressed',
            },
            {
                type: 'score',
                name: 'Goodwill',
                prompt: 'How much goodwill does resolving this need?',
                levels: ['None, policy answer', 'Small gesture', 'Full refund', 'Refund and apology'],
            },
        ],
    },

    'legal-threat': {
        label: 'Legal threat',
        situation:
            'If I do not receive a full refund by Friday I will instruct a solicitor and file a complaint with the regulator. I have screenshots of every broken promise from your chat agents.',
        conditions: [
            {
                type: 'noul',
                name: 'Escalate legal',
                prompt: 'Should this go to legal or a specialist queue?',
                yes: 'A legal, regulatory, or reputational threat',
                no: 'An ordinary customer complaint',
            },
            {
                type: 'score',
                name: 'Exposure',
                prompt: 'How much exposure does this carry for the company?',
                levels: ['None', 'Low', 'Serious', 'Severe'],
            },
        ],
    },

    'spam-or-real': {
        label: 'Spam or a real customer',
        situation:
            'CHEAP LUXURY WATCHES 80% OFF click here www.totally-legit-deals.biz buy now limited stock act fast!!!',
        conditions: [
            {
                type: 'noul',
                name: 'Is spam',
                prompt: 'Is this unsolicited spam rather than a real customer message?',
                yes: 'Spam, scam, or unrelated advertising',
                no: 'A genuine customer contacting us about an order or account',
            },
            {
                type: 'noul',
                name: 'Needs a reply',
                prompt: 'Does this deserve a human reply?',
                yes: 'A person should read it and answer',
                no: 'It can be closed without a reply',
            },
        ],
    },

    'refund-abuse': {
        label: 'Coordinated refund abuse',
        situation:
            'Ten new accounts from the same IP requested refunds for high-value electronics within 40 minutes, all claiming non-delivery, all using slightly different names and the same shipping city.',
        conditions: [
            {
                type: 'noul',
                name: 'Fraud review',
                prompt: 'Should this go to fraud review before any money moves?',
                yes: 'A coordinated pattern rather than unrelated customers',
                no: 'Normal claims that happen to arrive together',
            },
            {
                type: 'score',
                name: 'Confidence',
                prompt: 'How confident is the fraud signal?',
                levels: ['Weak', 'Suggestive', 'Strong', 'Conclusive'],
            },
        ],
    },

    'security-report': {
        label: 'Vulnerability report',
        situation:
            'Hi, hope you are well. I was poking at your API and noticed that changing the account_id in the /v2/invoices call returns other customers’ invoices without any error. Happy to share details. No rush, let me know where to send them.',
        conditions: [
            {
                type: 'noul',
                name: 'Page someone',
                prompt: 'Should this wake an on call engineer right now?',
                yes: 'Revenue affecting and still getting worse',
                no: 'Contained, or already recovering on its own',
            },
            {
                type: 'choice',
                name: 'Team',
                prompt: 'Which team should handle this?',
                options: [
                    { label: 'security', applies: 'Vulnerabilities, data exposure and abuse' },
                    { label: 'support', applies: 'General questions and follow ups' },
                    { label: 'billing', applies: 'Refunds, payments and invoices' },
                ],
            },
        ],
    },

    'invoice-fraud': {
        label: 'Supplier changed its IBAN',
        situation:
            'Email from our print supplier’s usual contact: "Please note our bank details have changed, new IBAN below, kindly process the outstanding 48,000 EUR invoice today so we can close our quarter." The reply-to address is the supplier name with an extra hyphen, and the invoice PDF has last quarter’s reference number.',
        conditions: [
            {
                type: 'noul',
                name: 'Pay today',
                prompt: 'Should the payment be made today as requested?',
                yes: 'A legitimate request that should be processed',
                no: 'Something must be verified before any money moves',
            },
            {
                type: 'choice',
                name: 'Team',
                prompt: 'Which team should handle this?',
                options: [
                    { label: 'security', applies: 'Fraud, impersonation and abuse' },
                    { label: 'billing', applies: 'Refunds, payments and invoices' },
                    { label: 'support', applies: 'General questions and follow ups' },
                ],
            },
        ],
    },

    'cert-expiry': {
        label: 'Certificate about to expire',
        situation:
            'Saturday 22:40. Monitoring: the TLS certificate for api.production expires in 5 hours 20 minutes. Auto-renewal has failed three times with a DNS validation error. Everything is currently green and serving normally.',
        conditions: [
            {
                type: 'noul',
                name: 'Page someone',
                prompt: 'Should this wake an on call engineer right now?',
                yes: 'Revenue affecting and still getting worse',
                no: 'Contained, or already recovering on its own',
            },
            {
                type: 'score',
                name: 'Severity',
                prompt: 'How severe is this incident?',
                levels: ['Negligible', 'Minor', 'Major', 'Critical'],
            },
        ],
    },

    'planned-load-test': {
        label: 'Alert during a load test',
        situation:
            'Alert: checkout latency p99 at 4.1 seconds, up from 380ms, error rate 2.2%. The engineering channel has a pinned message from this morning: "Load test against the checkout path 20:00-22:00 today, expect latency and synthetic errors, do not page." Current time is 21:10.',
        conditions: [
            {
                type: 'noul',
                name: 'Page someone',
                prompt: 'Should this wake an on call engineer right now?',
                yes: 'Revenue affecting and still getting worse',
                no: 'Contained, or already recovering on its own',
            },
            {
                type: 'noul',
                name: 'Real incident',
                prompt: 'Is this a real customer-facing incident?',
                yes: 'Genuine unplanned degradation affecting users',
                no: 'Expected behaviour from planned work',
            },
        ],
    },

    'login-loop': {
        label: 'Reproducible product bug',
        situation:
            'After the 2.4.1 deploy, signing in on iOS loops back to the splash screen. I can reproduce it every time on two devices. I cannot use the app at all.',
        conditions: [
            {
                type: 'choice',
                name: 'Ticket type',
                prompt: 'How should this ticket be classified?',
                options: [
                    { label: 'bug', applies: 'Something is broken that used to work' },
                    { label: 'feature', applies: 'A request for behaviour we do not have' },
                    { label: 'question', applies: 'The product works, the user needs guidance' },
                ],
            },
            {
                type: 'score',
                name: 'Severity',
                prompt: 'How severe is this issue?',
                levels: ['Negligible', 'Minor', 'Major', 'Critical'],
            },
        ],
    },

    'feature-request': {
        label: 'Feature request',
        situation:
            'Would love a dark mode in the dashboard. Not a bug, the current theme is just bright for late-night on-call. Happy to beta test.',
        conditions: [
            {
                type: 'choice',
                name: 'Ticket type',
                prompt: 'How should this ticket be classified?',
                options: [
                    { label: 'feature', applies: 'A request for behaviour we do not have' },
                    { label: 'bug', applies: 'Something is broken that used to work' },
                    { label: 'question', applies: 'The product works, the user needs guidance' },
                ],
            },
            {
                type: 'score',
                name: 'Priority',
                prompt: 'How soon should this be scheduled?',
                levels: ['Never', 'Someday', 'Next quarter', 'This sprint'],
            },
        ],
    },

    'strong-applicant': {
        label: 'Strong applicant',
        situation:
            'Applicant for a senior PHP role: former Laravel core contributor, 12 years shipping PHP, currently staff engineer at a public company, wants to go back to hands-on product work, salary inside the band.',
        conditions: [
            {
                type: 'noul',
                name: 'Interview',
                prompt: 'Should this application go to a first interview?',
                yes: 'Clear evidence of the depth the role needs',
                no: 'Would be a stretch on the core requirement',
            },
            {
                type: 'choice',
                name: 'Seniority',
                prompt: 'Which band does this experience fit?',
                options: [
                    { label: 'staff', applies: 'Sets direction across teams' },
                    { label: 'senior', applies: 'Owns delivery and mentors' },
                    { label: 'mid', applies: 'Solid delivery with guidance' },
                ],
            },
        ],
    },

    'silent-churn': {
        label: 'Account going quiet',
        situation:
            'Account review for Northwind Ltd, our second largest customer: daily active users dropped from 340 to 11 over the last six weeks, no support tickets, no complaints. Their renewal is in 19 days. The champion who bought the product left the company in August.',
        conditions: [
            {
                type: 'noul',
                name: 'Act this week',
                prompt: 'Does this need an owner assigned this week?',
                yes: 'Something is at stake that gets harder to fix by waiting',
                no: 'It can wait for the normal review cycle',
            },
            {
                type: 'score',
                name: 'Risk',
                prompt: 'How much revenue risk does this carry?',
                levels: ['None', 'Low', 'Serious', 'Severe'],
            },
        ],
    },

    'overqualified': {
        label: 'Overqualified candidate',
        situation:
            'Applicant for a mid-level support engineer role: former VP of Engineering at a 200-person company, 18 years experience, says they want "something calmer with less responsibility", accepts the posted salary which is a third of their last one, would report to someone with 4 years experience.',
        conditions: [
            {
                type: 'noul',
                name: 'Interview',
                prompt: 'Should this application go to a first interview?',
                yes: 'Worth an hour of the team’s time to explore',
                no: 'Clearly not a fit, decline now',
            },
            {
                type: 'choice',
                name: 'Main risk',
                prompt: 'What is the main risk with this hire?',
                options: [
                    { label: 'retention', applies: 'Can do the work but is likely to leave or be unhappy' },
                    { label: 'capability', applies: 'Cannot do the work required' },
                    { label: 'cost', applies: 'Too expensive for the budget' },
                ],
            },
        ],
    },
};
