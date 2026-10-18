<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * Field ids and states of the two slices of a mass sending - the only place they are spelled.
 * The slices come with the app package; which slices an installation uses is its own setting.
 *
 * Constants, not enums: the values go straight into storeItem() and JSON, an enum would add ->value everywhere.
 * States and box problems are plain words: `-` separates the parts of a view condition (cmd[]=d-<field>-=-<value>).
 */
final class Schema
{
    // one item = one mass sending
    public const CAMPAIGN_SUBJECT      = 'headline........';
    public const CAMPAIGN_BODY         = 'full_text.......';
    public const CAMPAIGN_FILES        = 'file............';
    public const CAMPAIGN_TO_HANDS     = 'text...........1';
    public const CAMPAIGN_FILTER       = 'text...........2';
    public const CAMPAIGN_TEMPLATE     = 'relation........';
    public const CAMPAIGN_STATE        = 'text...........3';
    public const CAMPAIGN_ERROR_TEXT   = 'text...........8';
    public const CAMPAIGN_ENVIRONMENT  = 'text...........4';
    public const CAMPAIGN_SENDER_BOX   = 'text...........5';
    public const CAMPAIGN_RECIPIENTS   = 'number.........1';
    public const CAMPAIGN_SENT_BY      = 'text...........6';
    public const CAMPAIGN_SENT_BY_NAME = 'text...........7';
    public const CAMPAIGN_STARTED_AT   = 'date...........1';
    public const CAMPAIGN_FINISHED_AT  = 'date...........2';

    // one item = one recipient of one sending, a test message too
    public const MESSAGE_NAME         = 'headline........';
    public const MESSAGE_CAMPAIGN     = 'relation........';
    public const MESSAGE_RECIPIENT    = 'relation.......1';
    public const MESSAGE_BOX          = 'text...........1';
    public const MESSAGE_OFFICIAL     = 'text...........2';
    public const MESSAGE_TEST         = 'switch.........1';
    public const MESSAGE_ENVIRONMENT  = 'text...........9';
    public const MESSAGE_SENDER_BOX   = 'text..........10';
    public const MESSAGE_STATE        = 'text...........3';
    public const MESSAGE_SENDER_REF   = 'text...........4';
    public const MESSAGE_DM_ID        = 'text...........5';
    public const MESSAGE_ERROR_CODE   = 'text...........6';
    public const MESSAGE_ERROR_TEXT   = 'text...........7';
    public const MESSAGE_DM_STATUS    = 'number.........1';
    public const MESSAGE_SENT_AT      = 'date...........1';
    public const MESSAGE_DELIVERED_AT = 'date...........2';
    public const MESSAGE_ACCEPTED_AT  = 'date...........3';
    public const MESSAGE_EVENTS       = 'text...........8';

    // the content fields the slice must have
    public const CAMPAIGN_FIELDS = [
        self::CAMPAIGN_SUBJECT,
        self::CAMPAIGN_BODY,
        self::CAMPAIGN_FILES,
        self::CAMPAIGN_TO_HANDS,
        self::CAMPAIGN_FILTER,
        self::CAMPAIGN_TEMPLATE,
        self::CAMPAIGN_STATE,
        self::CAMPAIGN_ERROR_TEXT,
        self::CAMPAIGN_ENVIRONMENT,
        self::CAMPAIGN_SENDER_BOX,
        self::CAMPAIGN_RECIPIENTS,
        self::CAMPAIGN_SENT_BY,
        self::CAMPAIGN_SENT_BY_NAME,
        self::CAMPAIGN_STARTED_AT,
        self::CAMPAIGN_FINISHED_AT,
    ];
    public const MESSAGE_FIELDS = [
        self::MESSAGE_NAME,
        self::MESSAGE_CAMPAIGN,
        self::MESSAGE_RECIPIENT,
        self::MESSAGE_BOX,
        self::MESSAGE_OFFICIAL,
        self::MESSAGE_TEST,
        self::MESSAGE_ENVIRONMENT,
        self::MESSAGE_SENDER_BOX,
        self::MESSAGE_STATE,
        self::MESSAGE_SENDER_REF,
        self::MESSAGE_DM_ID,
        self::MESSAGE_ERROR_CODE,
        self::MESSAGE_ERROR_TEXT,
        self::MESSAGE_DM_STATUS,
        self::MESSAGE_SENT_AT,
        self::MESSAGE_DELIVERED_AT,
        self::MESSAGE_ACCEPTED_AT,
        self::MESSAGE_EVENTS,
    ];

    // fields in the number column (text_stored=0): a text-stored date neither sorts nor compares
    public const CAMPAIGN_NUMBER_FIELDS = [self::CAMPAIGN_RECIPIENTS, self::CAMPAIGN_STARTED_AT, self::CAMPAIGN_FINISHED_AT];
    public const MESSAGE_NUMBER_FIELDS  = [
        self::MESSAGE_TEST,
        self::MESSAGE_DM_STATUS,
        self::MESSAGE_SENT_AT,
        self::MESSAGE_DELIVERED_AT,
        self::MESSAGE_ACCEPTED_AT,
    ];

    // field => the value its default (input_default) must give: a campaign added in the admin starts as a
    // draft, never as one being sent. The server writes every field of a message itself.
    public const CAMPAIGN_DEFAULTS = [self::CAMPAIGN_STATE => self::CAMPAIGN_STATE_DRAFT];
    public const MESSAGE_DEFAULTS  = [];

    // only a draft may change; from queued on the sending is a record and a change goes to a copy
    public const CAMPAIGN_STATE_DRAFT   = 'draft';
    public const CAMPAIGN_STATE_QUEUED  = 'queued';
    public const CAMPAIGN_STATE_SENDING = 'sending';
    public const CAMPAIGN_STATE_DONE    = 'done';
    public const CAMPAIGN_STATE_STOPPED = 'stopped';
    public const CAMPAIGN_STATE_ERROR   = 'error';
    public const CAMPAIGN_STATES  = [
        self::CAMPAIGN_STATE_DRAFT,
        self::CAMPAIGN_STATE_QUEUED,
        self::CAMPAIGN_STATE_SENDING,
        self::CAMPAIGN_STATE_DONE,
        self::CAMPAIGN_STATE_STOPPED,
        self::CAMPAIGN_STATE_ERROR,
    ];

    public const MESSAGE_STATE_QUEUED    = 'queued';
    public const MESSAGE_STATE_SENDING   = 'sending';
    public const MESSAGE_STATE_SENT      = 'sent';
    public const MESSAGE_STATE_FAILED    = 'failed';
    /** the request left, no answer came: never sent again by itself, looked up by the sender reference */
    public const MESSAGE_STATE_UNCERTAIN = 'uncertain';
    public const MESSAGE_STATE_SKIPPED   = 'skipped';
    public const MESSAGE_STATE_STOPPED   = 'stopped';
    public const MESSAGE_STATES    = [
        self::MESSAGE_STATE_QUEUED,
        self::MESSAGE_STATE_SENDING,
        self::MESSAGE_STATE_SENT,
        self::MESSAGE_STATE_FAILED,
        self::MESSAGE_STATE_UNCERTAIN,
        self::MESSAGE_STATE_SKIPPED,
        self::MESSAGE_STATE_STOPPED,
    ];

    // ERROR_CODE of a skipped message
    /** the item has no data box */
    public const BOX_MISSING   = 'missing';
    /** the data box field holds no box id */
    public const BOX_FORMAT    = 'format';
    /** the register of data boxes has not the box, or has it for another IČO than the item's */
    public const BOX_FOREIGN   = 'foreign';
    /** ISDS reports the box as not active (CheckDataBox) */
    public const BOX_INACTIVE  = 'inactive';
    /** another item gets the letter to the same box */
    public const BOX_DUPLICATE = 'duplicate';
    /** the IČO field holds something else than an IČO, so the box cannot be checked against it */
    public const BOX_ICO       = 'ico';
    /** the register has the box, but not of a kind a letter may go to: a part of an authority, a person
     *  or company acting as one, or an authority that is no municipality or city district by its name */
    public const BOX_KIND      = 'kind';
    public const BOX_PROBLEMS  = [
        self::BOX_MISSING,
        self::BOX_FORMAT,
        self::BOX_FOREIGN,
        self::BOX_INACTIVE,
        self::BOX_DUPLICATE,
        self::BOX_ICO,
        self::BOX_KIND,
    ];

    // `kind` of an entry in MESSAGE_EVENTS. The timeline cannot be derived from the times alone: they miss
    // the time stamp, a timeout and a lookup. dmMessageStatus 7, 9 and 10 change MESSAGE_DM_STATUS only.
    public const EVENT_QUEUED         = 'queued';
    public const EVENT_SENDING        = 'sending';
    /** dmMessageStatus 1 */
    public const EVENT_SUBMITTED      = 'submitted';
    /** dmMessageStatus 2 */
    public const EVENT_STAMPED        = 'stamped';
    /** dmMessageStatus 3 - failed the antivirus check: a final state, the message is never delivered */
    public const EVENT_AV             = 'av';
    /** dmMessageStatus 4 */
    public const EVENT_DELIVERED      = 'delivered';
    /** dmMessageStatus 5 - accepted ten days after the delivery */
    public const EVENT_FICTION        = 'fiction';
    /** dmMessageStatus 6 */
    public const EVENT_LOGIN          = 'login';
    /** dmMessageStatus 8 - the recipient's box was closed; even a delivered message (4, 5) can end here */
    public const EVENT_UNDELIVERABLE  = 'undeliverable';
    public const EVENT_FAILED         = 'failed';
    /** may have been sent: no answer, one that does not say what ISDS did, or an error AA does not know */
    public const EVENT_TIMEOUT        = 'timeout';
    public const EVENT_LOOKUP_MISSING = 'lookup-missing';
    public const EVENT_LOOKUP_FOUND   = 'lookup-found';
    public const EVENT_SKIPPED        = 'skipped';
    public const EVENT_STOPPED        = 'stopped';
    /** a person confirmed an uncertain message is not among the sent ones; the entry keeps who (`by`) */
    public const EVENT_VERIFIED       = 'verified';
    public const EVENT_KINDS          = [
        self::EVENT_QUEUED,
        self::EVENT_SENDING,
        self::EVENT_SUBMITTED,
        self::EVENT_STAMPED,
        self::EVENT_AV,
        self::EVENT_DELIVERED,
        self::EVENT_FICTION,
        self::EVENT_LOGIN,
        self::EVENT_UNDELIVERABLE,
        self::EVENT_FAILED,
        self::EVENT_TIMEOUT,
        self::EVENT_LOOKUP_MISSING,
        self::EVENT_LOOKUP_FOUND,
        self::EVENT_SKIPPED,
        self::EVENT_STOPPED,
        self::EVENT_VERIFIED,
    ];
}
