# Kasa Academy — permissions matrix

Every role in the Kasa Learning Academy, and what each one may do.

**This file is generated.** It is built from the capability map in
`includes/roles/capabilities.php`, which is the same array the role
installer reads, so it cannot drift away from what the plugin actually
does. Editing it by hand achieves nothing; change the map and
regenerate from **Kasa Academy → Permissions matrix**.

Plugin version 1.0.0 · roles version 1.2.0 · generated 1 September 2026

## The four roles

| Role | Slug | Notes |
| --- | --- | --- |
| Learner | `kasa_learner` | A child or young person taking part in Academy programmes. The default role for every new registration. |
| Facilitator | `group_leader` | Runs a group of learners. This is the LearnDash group_leader role, renamed. Registering a separate kasa_facilitator role would break LearnDash group reporting and leave ProPanel empty, because LearnDash would no longer recognise the user as a group leader. |
| Implementation Partner | `kasa_partner` | A school or organisation registering a cohort of children. Sees a roster with completion status for its own cohorts and nothing else. |
| Administrator | `administrator` | Unrestricted. Scoping helpers return every group for an administrator. |

## Learning

| Capability | Learner | Facilitator | Implementation Partner | Administrator |
| --- | :-: | :-: | :-: | :-: |
| View own dashboard<br>`kasa_view_dashboard` | Yes | Yes | Yes | Yes |
| View facilitator only materials<br>`kasa_view_facilitator_resources` | — | Yes | — | Yes |

- **View own dashboard** — Reach the signed-in landing screen of the Academy.
- **View facilitator only materials** — The Technovation Transition Guide, Troubleshooting and Recovery Playbook, Selection Rubric and Facilitator Guide. These carry safeguarding procedures and assessment criteria, so learners must never reach them.

## Applications

| Capability | Learner | Facilitator | Implementation Partner | Administrator |
| --- | :-: | :-: | :-: | :-: |
| Submit an application<br>`kasa_submit_application` | Yes | — | Yes | Yes |
| View own application status<br>`kasa_view_own_application` | Yes | Yes | Yes | Yes |
| Approve or decline an application<br>`kasa_review_applications` | — | Yes | — | Yes |
| Mark a learner as selected for competition<br>`kasa_select_for_competition` | — | — | — | Yes |
| Open or close an application season<br>`kasa_manage_seasons` | — | — | — | Yes |

- **Submit an application** — Start a new application. Partners submit on behalf of an organisation registering a group of children.
- **View own application status** — See the state of an application this user submitted.
- **Approve or decline an application** — Decide on applications from the groups this user leads. Deliberately withheld from partners, who submit but never review.
- **Mark a learner as selected for competition** — Administrator only.
- **Open or close an application season** — Administrator only.

## Group and cohort

| Capability | Learner | Facilitator | Implementation Partner | Administrator |
| --- | :-: | :-: | :-: | :-: |
| See learners inside own groups<br>`kasa_view_group_learners` | — | Yes | Yes | Yes |
| See a learner quiz answers and written reflections<br>`kasa_view_group_detail` | — | Yes | — | Yes |
| Export progress for own groups<br>`kasa_export_group_progress` | — | Yes | Yes | Yes |
| Add or remove learners from own groups<br>`kasa_manage_group_members` | — | Yes | — | Yes |
| See own organisation cohorts<br>`kasa_view_org_cohorts` | — | — | Yes | Yes |

- **See learners inside own groups** — The roster. Always scoped by group membership, never a list of every learner on the platform.
- **See a learner quiz answers and written reflections** — Deliberately withheld from partners. Quiz answers and reflections contain children own words and a partner organisation has no need for them.
- **Export progress for own groups** — Export is still bounded by the same group scope as on-screen data.
- **Add or remove learners from own groups** — Requires the LearnDash setting Group Leader User Management to be on as well. A capability alone is not enough.
- **See own organisation cohorts** — Partner scoping. Resolves groups through the kasa_organisation_id link rather than through group leadership alone.

## Content and site

| Capability | Learner | Facilitator | Implementation Partner | Administrator |
| --- | :-: | :-: | :-: | :-: |
| Upload and remove shared documents<br>`kasa_manage_resources` | — | Yes | — | Yes |
| Administer the Academy<br>`kasa_manage_academy` | — | — | — | Yes |

- **Upload and remove shared documents** — Put a handbook, manual or rulebook on the dashboard for others to download, and take one down again. Facilitators do this from the dashboard itself rather than wp-admin, so this is the only thing standing between a signed-in learner and a file upload: it is granted to facilitators and administrators and to nobody else. Viewing a document is a separate question, answered per document by kasa_view_facilitator_resources.
- **Administer the Academy** — Administrator catch all, used to gate plugin settings screens.

## LearnDash integration

| Capability | Learner | Facilitator | Implementation Partner | Administrator |
| --- | :-: | :-: | :-: | :-: |
| LearnDash group leader recognition<br>`group_leader` | — | Yes | Yes | — |
| ProPanel reporting widgets<br>`propanel_widgets` | — | Yes | Yes | Yes |
| Reach the Advanced Quiz screen<br>`wpProQuiz_show` | — | Yes | — | Yes |
| Open a quiz attempt and its answers<br>`wpProQuiz_show_statistics` | — | Yes | — | Yes |

- **LearnDash group leader recognition** — LearnDash decides who is a group leader with a capability check, not a role check. learndash_is_group_leader_user() calls user_can( $user, LEARNDASH_GROUP_LEADER_CAPABILITY_CHECK ), and that constant is defined as the string group_leader in learndash-scalar-constants.php. Granting this capability is what makes group reporting work for a custom role. Administrators do not need it, they are recognised through manage_options instead.
- **ProPanel reporting widgets** — LearnDash grants this once, to the roles that exist at that moment, and records the fact in the learndash_modules_reports_capabilities_granted option. A role created afterwards never receives it, so this plugin grants it explicitly.
- **Reach the Advanced Quiz screen** — The capability LearnDash registers its Advanced Quiz page with. It is needed to open the statistics module at all, but it also opens the quiz builder, the question editor and the import and export tools, none of which a facilitator may use. Kasa_Guards therefore allows only module=statistics for anyone who is not an administrator and refuses every other module on that page.
- **Open a quiz attempt and its answers** — What LearnDash requires to open the quiz statistics screen, where a learner's individual answers are read. Granted to the Facilitator so the matrix row "See a learner's quiz answers, own groups" is actually deliverable, and deliberately withheld from the Implementation Partner. The capability carries no notion of a group, so it is paired with a scope guard in Kasa_Guards that resolves the attempt to the learner who made it and refuses anyone outside their own groups.

## Scoping

A capability says what kind of thing a role may do. It does not say
whose learners they may do it to. Every question of the second kind is
answered in one place, `Kasa_Scope`, and every screen asks it rather
than working it out again.

| Role | Sees |
| --- | --- |
| Learner | No groups. Their own work only. |
| Facilitator | The groups they lead, through LearnDash group leadership. |
| Implementation Partner | The groups carrying their organisation, and nothing else. Leader assignments are ignored for partners on purpose, so one wrong assignment cannot widen what they see. |
| Administrator | Everything. |

Everything fails closed. A partner with no organisation, an organisation
that was deleted, a signed out visitor, an unrecognised role: all resolve
to no groups, which callers must read as *none* and never as *all*.

## What partners deliberately cannot see

A partner organisation gets a roster and completion status. It does not
get quiz answers or written reflections, because those are children's own
words and a partner has no need of them. In LearnDash a written reflection
is stored as an essay, and an uploaded piece of work as an assignment;
both are refused to partners on the front end and in wp-admin.

> This was a judgement call, flagged in the brief for confirmation. It is
> built as described and can be widened if the client asks.

