<?php

namespace AA\Doit;

use AA\IO\DB\DB_AA;

class ConvertSliceEncoding {

    protected $slice_id = '';

    public function __construct($slice_id='') {
        $this->setSliceId($slice_id);
    }

    /**
     * @param mixed $slice_id
     */
    public function setSliceId(string $slice_id): ConvertSliceEncoding {
        $this->slice_id = $slice_id;
        return $this;
    }

    /**
     * @return string
     */
    public function doit() {
        huhl('lets do it '. $this->slice_id);
        //$SQL =  "UPDATE content SET text = convert(text using utf8) WHERE item_id IN ( SELECT id FROM item WHERE slice_id=0x" . quote($this->slice_id).")";
        $SQL =  "UPDATE content SET text = cast(convert(convert(cast(text as binary) using cp1250) using utf8) as binary) WHERE item_id IN ( SELECT id FROM item WHERE slice_id=0x" . quote($this->slice_id).")";
        /*
          alerts_filter - conds, description
          auth_group - username, groups      ??
          auth_log   - result
          auth_user  - username, password    ??
          change_record - value
          constant - name, value, ancestors ??, description
          content - text
          cron - * ??
          discussion - subject,  author,  	e_mail, body, url_address,  url_description,  	remote_addr ??,  	free1,   	free2
          ef_categories - category, category_name
           ef_permissions - 
        */
        huhl($SQL);
        huhl(DB_AA::sql($SQL));
    }
}
