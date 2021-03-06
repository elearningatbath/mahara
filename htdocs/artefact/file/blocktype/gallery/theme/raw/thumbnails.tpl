<div class="thumbnails" id="thumbnails{$instanceid}">
    {foreach from=$images item=image}
        <div style="float:left;{if $frame} padding: 3px;{/if}" class="thumb">
        <a rel="{$image.slimbox2}" href="{$image.link}" title="{$image.title}" target="_blank">
            <img src="{$image.source}" alt="{$image.title}" title="{$image.title}" {if $frame}class="frame"{/if} />
        </a>
        {if $showdescription && $image.title}<div class="caption" style="width: {$captionwidth}px;">{$image.title|safe}</div>{/if}
        </div>
    {/foreach}
    <div class="cb"></div>
</div>
<script type="text/javascript">
$j(function() {
    if ($j('#thumbnails{$instanceid}')) {
        // adjust height of image + description box to align things up
        var maxHeight = Math.max.apply(null, $j('#thumbnails{$instanceid} .thumb').map(function() {
            return $j(this).height();
        }).get());
        $j('#thumbnails{$instanceid} .thumb').each(function() {
            $j(this).css('height', maxHeight);
        });
    }
});
</script>
{if isset($copyright)}<div class="cb" id="lbBottom">{$copyright|safe}</div>{/if}
