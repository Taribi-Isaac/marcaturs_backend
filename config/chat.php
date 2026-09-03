<?php

return [

    /*
     | Maximum characters for a conversational text message.
     | The foundational documents do not specify a legal maximum; this is platform config.
     */
    'message_max_characters' => (int) env('CHAT_MESSAGE_MAX_CHARS', 5000),

];
