<?php 

namespace jdavidbakr\MailTracker;

class MailTracker implements \Swift_Events_SendListener {

	protected $hash;
	/**
	 * Inject the tracking code into the message
	 */
	public function beforeSendPerformed(\Swift_Events_SendEvent $event)
	{
		$message = $event->getMessage();
    	$headers = $message->getHeaders();
    	$hash = str_random(32);

    	$original_content = $message->getBody();

        if ($message->getContentType() === 'text/html' ||
            ($message->getContentType() === 'multipart/alternative' && $message->getBody())
        ) {
        	$message->setBody($this->addTrackers($message->getBody(), $hash));
        }

        foreach ($message->getChildren() as $part) {
            if (strpos($part->getContentType(), 'text/html') === 0) {
                $converter->setHTML($part->getBody());
                $part->setBody($this->addTrackers($message->getBody(), $hash));
            }
        }    	

    	Model\SentEmail::create([
    			'hash'=>$hash,
    			'headers'=>$headers->toString(),
    			'sender'=>$headers->get('from')->getFieldBody(),
    			'recipient'=>$headers->get('to')->getFieldBody(),
    			'subject'=>$headers->get('subject')->getFieldBody(),
    			'content'=>$original_content,
    		]);

    	// Purge old records
    	if(config('mail-tracker.expire-days') > 0) {
    		Model\SentEmail::where('created_at','<',\Carbon\Carbon::now()->subDays(config('mail-tracker.expire-days')))->delete();
    	}
	}

    public function sendPerformed(\Swift_Events_SendEvent $event)
    {
    	//
    }

    protected function addTrackers($html, $hash)
    {
    	if(config('mail-tracker.inject-pixel')) {
	    	$html = $this->injectTrackingPixel($html, $hash);
    	}
    	if(config('mail-tracker.track-links')) {
    		$html = $this->injectLinkTracker($html, $hash);
    	}

    	return $html;
    }

    protected function injectTrackingPixel($html, $hash)
    {
    	// Append the tracking url
    	$tracking_pixel = '<img src="'.action('\jdavidbakr\MailTracker\MailTrackerController@getT',[$hash]).'" />';

    	$linebreak = str_random(32);
    	$html = str_replace("\n",$linebreak,$html);

    	if(preg_match("/^(.*<body[^>]*>)(.*)$/", $html, $matches)) {
    		$html = $matches[1].$tracking_pixel.$matches[2];
    	} else {
    		$html = $tracking_pixel . $html;
    	}
    	$html = str_replace($linebreak,"\n",$html);

    	return $html;
    }

    protected function injectLinkTracker($html, $hash)
    {
    	$this->hash = $hash;

    	$html = preg_replace_callback("/(<a[^>]*href=['\"])([^'\"]*)/",
    			array($this, 'inject_link_callback'),
    			$html);

    	return $html;
    }

    protected function inject_link_callback($matches)
    {
    	return $matches[1].action('\jdavidbakr\MailTracker\MailTrackerController@getL',
    		[
    			base64_encode($matches[2]),
    			$this->hash
    		]);
    }
}