import * as Msg from "./msg"
import Emitter from "./emitter"
import $ from "jquery"
import * as Ajax from "./ajax"
import hljs from "highlightjs"

/**
 * Вспомогательные функции
 */


/**
 * Переводит первый символ в верхний регистр
 */
export function ucfirst(str) {
    const f = str.charAt(0).toUpperCase();
    return f + str.substr(1, str.length - 1);
}

/**
 * Выделяет все chekbox с определенным css классом
 */
export function checkAll(cssclass, checkbox, invert) {
    $("." + cssclass).each(function(index, item) {
        if(invert) {
            $(item).attr("checked", !$(item).attr("checked"));
        } else {
            $(item).attr("checked", $(checkbox).attr("checked"));
        }
    });
}


/**
 * Предпросмотр
 */
export function textPreview(textId, save, divPreview) {
    const text = (BLOG_USE_TINYMCE) ? tinyMCE.activeEditor.getContent() : $("#" + textId).val();
    const ajaxUrl = aRouter["ajax"] + "preview/text/";
    const form_comment_mark = $("#form_comment_mark")[0];
    const ajaxOptions = {
        text: text,
        save: save,
        form_comment_mark: form_comment_mark ? (form_comment_mark.checked ? "on" : "off") : "off",
    };
    Emitter.emit("tools_textpreview_ajax_before");
    Ajax.ajax(ajaxUrl, ajaxOptions, function(result) {
        if(!result) {
            Msg.error("Error", "Please try again later");
        }
        if(result.bStateError) {
            Msg.error(result.sMsgTitle || "Error", result.sMsg || "Please try again later");
        } else {
            if(!divPreview) {
                divPreview = "text_preview";
            }
            const elementPreview = $("#" + divPreview);
            Emitter.emit("tools_textpreview_display_before");
            if(elementPreview.length) {
                elementPreview.html(result.sText);
                elementPreview.find(`pre code`).each((k, el) => hljs.highlightBlock(el));
                Emitter.emit("tools_textpreview_display_after");
            }
        }
    });
}

/**
 * Возвращает выделенный текст на странице
 */
export function getSelectedText() {
    let text = "";
    if(window.getSelection) {
        text = window.getSelection().toString();
    } else if(window.document.selection) {
        let sel = window.document.selection.createRange();
        text = sel.text || sel;
        if(text.toString) {
            text = text.toString();
        } else {
            text = "";
        }
    }
    return text;
}


export const options = {
    debug: true,
};

/**
 * Дебаг сообщений
 */
export function debug() {
    if(options.debug) {
        Log.apply(this, arguments);
    }
}

/**
 * Лог сообщений
 */
export function Log() {
   /* if(window.console && window.console.log) {
        //Function.prototype.bind.call(console.log, console).apply(console, arguments);
    } else {
        //alert(msg);
    }*/
}
