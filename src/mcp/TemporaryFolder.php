<?php

namespace arifje\craftvideodownloader\mcp;

/**
 * What an MCP download says when the field's upload location resolves to the
 * user's temporary-uploads folder.
 *
 * WHY refuse over MCP when the control panel does not: in the CP that folder
 * is a staging area. The editor's entry is unsaved, and Craft moves the file
 * into the real upload location when the entry is saved. Over MCP nothing
 * moves it. The asset has no volume, so it cannot be put in a relation field
 * by natural key, cannot be previewed or have frames taken from it, and
 * Transcoder keeps retrying its inspection. In the dev.skoften.net test this
 * happened twice, both times reported as a successful "done".
 *
 * The usual cause is an upload location that uses the owner (for example
 * "{owner.id}") on a field inside a Matrix block. It only resolves when the
 * entry passed is the block itself, not the article that owns it.
 *
 * Craft-free on purpose, so tests/php/run.php can cover it.
 */
final class TemporaryFolder
{
    /**
     * The refusal for a download whose folder resolved to the temporary
     * uploads folder.
     *
     * @param int|null $entryId the entryId the caller passed
     * @param bool $fieldOnEntryLayout whether the field sits directly on that entry's own layout
     */
    public static function refusal(?int $entryId, bool $fieldOnEntryLayout): string
    {
        $where = 'The video would land in your private temporary uploads folder, where it cannot be attached to an entry or published, so the download was not started.';

        if ($entryId === null) {
            return $where . ' This field\'s upload location depends on the entry: pass entryId. For a field inside a Matrix block, pass the block\'s own entry id (create_nested_entry returns it as blockId).';
        }

        if (!$fieldOnEntryLayout) {
            return $where . " The field is not on entry {$entryId}'s own layout, so it sits inside a Matrix block: pass that block's entry id as entryId (create_nested_entry returns it as blockId), not the id of the entry that owns the block.";
        }

        return $where . " The upload location could not be resolved for entry {$entryId}. Check the field's upload location, or save the entry first if it uses the entry's id or slug.";
    }

    /**
     * The warning video_download_status adds when a finished download sits in
     * the temporary uploads folder, which only older jobs (started before the
     * refusal above) or the control panel can produce.
     */
    public static function statusWarning(): string
    {
        return 'This file is in your temporary uploads folder, not in a volume: it has no natural key, so it cannot be put in a field with update_entry. Download it again with the entryId the field\'s upload location needs (for a field inside a Matrix block, the block\'s own entry id).';
    }
}
