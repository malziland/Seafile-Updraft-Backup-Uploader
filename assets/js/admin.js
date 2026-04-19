(function(){
    var N = sbuAdmin.nonce;
    var wait = sbuAdmin.i18n.wait;
    var optKey = sbuAdmin.optKey;

    function P(a,x){return fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+a+'&_nonce='+N+(x||'')}).then(function(r){var ct=r.headers.get('content-type')||'';if(!r.ok||ct.indexOf('json')===-1){return r.text().then(function(t){var msg;if(r.status===503||t.indexOf('temporarily unavailable')!==-1||t.indexOf('maintenance')!==-1){msg='Der Server ist vorübergehend überlastet. Der Vorgang läuft im Hintergrund weiter.';}else if(r.status>=500){msg='Server-Fehler (HTTP '+r.status+'). Der Vorgang läuft möglicherweise im Hintergrund weiter.';}else{msg=t.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').substring(0,150).trim()||'Fehler (HTTP '+r.status+')';}return {success:false,data:msg};});}return r.json();});}

    // Save settings (button only, no auto-save)
    var saveStatus2 = document.getElementById('sbu-save-status2');

    function showSaveMsg(msg,ok){if(saveStatus2){saveStatus2.textContent=msg;saveStatus2.style.color=ok?'#00a32a':'#d63638';saveStatus2.className='sbu-save-status show';if(ok)setTimeout(function(){saveStatus2.classList.remove('show');},3000);}}

    function doSave(){
        try{
        var f=document.getElementById('sbu-settings-form');if(!f){alert('Formular nicht gefunden');return;}
        var getValue=function(n){var el=f.querySelector('[name="'+optKey+'['+n+']"]');return el?el.value:'';};
        var getChecked=function(n){var el=f.querySelector('[name="'+optKey+'['+n+']"]');return el&&el.checked?'1':'0';};
        var data='&sbu_url='+encodeURIComponent(getValue('url'))
            +'&sbu_user='+encodeURIComponent(getValue('user'))
            +'&sbu_pass='+encodeURIComponent(getValue('pass'))
            +'&sbu_lib='+encodeURIComponent(getValue('lib'))
            +'&sbu_folder='+encodeURIComponent(getValue('folder'))
            +'&sbu_chunk='+encodeURIComponent(getValue('chunk'))
            +'&sbu_retention='+encodeURIComponent(getValue('retention'))
            +'&sbu_email='+encodeURIComponent(getValue('email'))
            +'&sbu_notify='+encodeURIComponent(getValue('notify'))
            +'&sbu_auto='+getChecked('auto')
            +'&sbu_del_local='+getChecked('del_local')
            +'&sbu_debug_log='+getChecked('debug_log');
        showSaveMsg('Speichern...',true);
        P('sbu_save_settings',data).then(function(d){
            if(d.success){
                showSaveMsg('✓ Gespeichert',true);
            }else{
                showSaveMsg('✗ '+( d.data||'Fehler'),false);
            }
        }).catch(function(x){
            showSaveMsg('✗ Verbindungsfehler',false);
        });
        }catch(e){alert('Speichern fehlgeschlagen: '+e.message);console.error('doSave error:',e);}
    }

    document.getElementById('sbu-save').addEventListener('click',doSave);

    // Reset settings
    document.getElementById('sbu-reset').addEventListener('click',function(){
        if(!confirm('Alle Einstellungen auf Standardwerte zurücksetzen?'))return;
        P('sbu_reset_settings').then(function(d){if(d.success)location.reload();});
    });

    // Progress bar helpers
    function showProgress(indeterminate){var p=document.getElementById('sp');p.style.display='block';if(indeterminate)p.classList.add('indeterminate');else p.classList.remove('indeterminate');document.getElementById('spf').style.width='0%';}
    function hideProgress(){var p=document.getElementById('sp');p.style.display='none';p.classList.remove('indeterminate');document.getElementById('spf').style.width='0%';}

    // Log category filter. Keeps the current choice across refreshes and
    // applies it client-side — the server always ships the full log so the
    // filter is instantly reversible.
    var logFilterMap = {
        all: null,
        errors:  ['FEHLER','WARNUNG','RETRY'],
        restore: ['RESTORE','UPLOAD','RATE','INFO'],
        debug:   ['TICK','BATCH','CHUNK']
    };
    function getLogFilter(){var sel=document.getElementById('sbu-log-filter');return sel?sel.value:'all';}
    function applyLogFilter(){
        var al=document.getElementById('alc');if(!al)return;
        var allow=logFilterMap[getLogFilter()];
        var lines=al.querySelectorAll('.sbu-log-line');
        for(var i=0;i<lines.length;i++){
            var cat=lines[i].getAttribute('data-cat')||'OTHER';
            lines[i].style.display=(!allow||allow.indexOf(cat)!==-1)?'':'none';
        }
    }
    var logFilterSel=document.getElementById('sbu-log-filter');
    if(logFilterSel){logFilterSel.addEventListener('change',applyLogFilter);applyLogFilter();}

    // Refresh activity log via AJAX
    function refreshLog(){P('sbu_get_log').then(function(d){if(d.success){var el=document.getElementById('alc');if(el){el.innerHTML=d.data||'<span class="dim">'+sbuAdmin.i18n.noActivity+'</span>';applyLogFilter();}}}).catch(function(){});}

    window.sA=function(a,b){var e=document.getElementById('sr');e.className='ld';e.style.display='block';e.textContent=wait;if(b)b.disabled=true;showProgress(true);var extra=(a==='sbu_test')?getFormCreds()+'&sbu_lib='+encodeURIComponent(libSelect.value)+'&sbu_folder='+encodeURIComponent(folderSelect.value):'';P(a,extra).then(function(d){hideProgress();e.className=d.success?'ok':'er';e.innerHTML='<pre style="margin:0;white-space:pre-wrap;font-size:13px">'+(d.data||'')+'</pre>';if(b)b.disabled=false;if(a==='sbu_upload'){document.getElementById('sbu-upb').style.display='none';stallCount=0;loadBL(true);refreshLog();}if(a==='sbu_test'){loadBL(true);refreshLog();}}).catch(function(x){hideProgress();e.className='er';e.textContent=x.message||'Verbindungsfehler';if(b)b.disabled=false;if(a==='sbu_upload'){document.getElementById('sbu-upb').style.display='none';stallCount=0;}});};
    window.sDl=function(dir,f){if(!confirm(f))return;var e=document.getElementById('sr');e.className='ld';e.style.display='block';e.textContent='Download...';showProgress(true);P('sbu_download','&dir='+encodeURIComponent(dir)+'&file='+encodeURIComponent(f)).then(function(d){hideProgress();e.className=d.success?'ok':'er';e.textContent=d.data||'';refreshLog();}).catch(function(x){hideProgress();e.className='er';e.textContent=x.message;});};
    window.sDe=function(dir){if(!confirm(dir+'?'))return;var e=document.getElementById('sr');e.className='ld';e.style.display='block';e.textContent='...';P('sbu_delete','&dir='+encodeURIComponent(dir)).then(function(d){e.className=d.success?'ok':'er';e.textContent=d.data||'';if(d.success){loadBL(true);refreshLog();}});};
    window.sbuToggle=function(id,link){var el=document.getElementById(id);if(!el)return;var show=el.style.display==='none';el.style.display=show?'block':'none';link.textContent=show?sbuAdmin.i18n.hide:sbuAdmin.i18n.show;};
    window.sDlAll=function(dir){if(!confirm(sbuAdmin.i18n.restoreConfirm))return;var e=document.getElementById('sr');e.className='ld';e.style.display='block';e.textContent=sbuAdmin.i18n.downloadingAll;showProgress(true);P('sbu_download_all','&dir='+encodeURIComponent(dir)).then(function(d){hideProgress();if(d.success){e.className='ok';e.innerHTML='<pre style="margin:0;white-space:pre-wrap;font-size:13px">'+(d.data||'')+'</pre>';}else{e.className='ld';e.innerHTML='<pre style="margin:0;white-space:pre-wrap;font-size:13px">'+(d.data||'')+'\n\n'+sbuAdmin.i18n.downloadProgress+'</pre>';}refreshLog();}).catch(function(x){hideProgress();e.className='ld';e.textContent=sbuAdmin.i18n.downloadTimeout;});};

    // Export log as .txt download
    window.sbuExportLog=function(){P('sbu_export_log').then(function(d){if(d.success&&d.data){var b=new Blob([d.data],{type:'text/plain'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='seafile-backup-log-'+new Date().toISOString().slice(0,10)+'.txt';a.click();}}).catch(function(x){alert(x.message);});};

    // Export anonymized log (host, library, folder path, e-mail, IPs stripped) — for support sharing.
    window.sbuExportLogAnon=function(){P('sbu_export_log_anon').then(function(d){if(d.success&&d.data){var b=new Blob([d.data],{type:'text/plain'});var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='seafile-backup-log-anon-'+new Date().toISOString().slice(0,10)+'.txt';a.click();}}).catch(function(x){alert(x.message);});};

    // Dismiss the post-restore success banner.
    window.sbuDismissRestoreBanner=function(){var el=document.getElementById('sbu-restore-banner');if(el)el.style.display='none';P('sbu_dismiss_restore_banner').catch(function(){});};

    // Clear log
    window.sbuClearLog=function(){if(!confirm(sbuAdmin.i18n.clearLogConfirm))return;P('sbu_clear_log').then(function(d){if(d.success)refreshLog();});};

    // Delegated dispatcher for data-sbu-action buttons. Replaces the inline
    // onclick="..." handlers from admin-page.php — CSP-safer, and keeps the
    // view template logic-free. Each key maps to a zero-arg entry point; sA()
    // callers pass the triggering button as "this" equivalent so the existing
    // disable/enable flow still works.
    var sbuActions = {
        'test':                   function(btn){ window.sA('sbu_test', btn); },
        'upload':                 function(btn){ window.sA('sbu_upload', btn); },
        'pause':                  function(){ window.sbuPause(); },
        'resume':                 function(){ window.sbuResume(); },
        'abort':                  function(){ window.sbuAbort(); },
        'dismiss-restore-banner': function(){ window.sbuDismissRestoreBanner(); },
        'export-log':             function(){ window.sbuExportLog(); },
        'export-log-anon':        function(){ window.sbuExportLogAnon(); },
        'clear-log':              function(){ window.sbuClearLog(); },
        'toggle-files':           function(btn){ window.sbuToggle(btn.getAttribute('data-target'), btn); },
        'restore-all':            function(btn){ window.sDlAll(btn.getAttribute('data-dir')); },
        'delete-backup':          function(btn){ window.sDe(btn.getAttribute('data-dir')); },
        'download-file':          function(btn){ window.sDl(btn.getAttribute('data-dir'), btn.getAttribute('data-file')); }
    };
    var sbuRoot = document.querySelector('.wrap.sbu') || document;
    sbuRoot.addEventListener('click', function(e){
        var btn = e.target.closest ? e.target.closest('[data-sbu-action]') : null;
        if (!btn) return;
        var key = btn.getAttribute('data-sbu-action');
        var fn  = sbuActions[key];
        if (fn) { e.preventDefault(); fn(btn); }
    });

    // Rotate the external cron key. Shown in the "Erweitert" section —
    // not something most users will touch, but the button lets them
    // revoke a leaked key without DB access.
    var rotateBtn = document.getElementById('sbu-rotate-cron-key');
    if (rotateBtn) {
        rotateBtn.addEventListener('click', function(){
            if (!confirm(sbuAdmin.i18n.rotateCronConfirm)) return;
            var status = document.getElementById('sbu-rotate-cron-status');
            rotateBtn.disabled = true;
            if (status) { status.className = 'sbu-picker-status show'; status.textContent = sbuAdmin.i18n.wait; }
            P('sbu_rotate_cron_key').then(function(d){
                rotateBtn.disabled = false;
                if (!d.success) {
                    if (status) { status.className = 'sbu-picker-status show er'; status.textContent = d.data || 'Fehler'; }
                    return;
                }
                if (status) { status.className = 'sbu-picker-status show ok'; status.textContent = sbuAdmin.i18n.rotateCronOk; }
                // Reload so the rendered crontab examples pick up the new key.
                setTimeout(function(){ location.reload(); }, 1200);
            }).catch(function(x){
                rotateBtn.disabled = false;
                if (status) { status.className = 'sbu-picker-status show er'; status.textContent = x.message || 'Verbindungsfehler'; }
            });
        });
    }

    function loadBL(silent){var e=document.getElementById('bl');if(!silent)e.innerHTML='<div class="sbu-progress" style="display:block"><div class="sbu-progress-bar"><div class="sbu-progress-fill" style="width:30%;animation:sbu-slide 1.2s ease-in-out infinite"></div></div></div>';P('sbu_list',getFormCreds()).then(function(d){e.innerHTML=d.success?d.data:'<p style="color:#d63638">'+d.data+'</p>';}).catch(function(x){if(!silent)e.innerHTML=x.message;});}
    loadBL();

    // === Library & Folder Picker ===
    var libSelect = document.getElementById('sbu-lib');
    var libLoad = document.getElementById('sbu-lib-load');
    var libStatus = document.getElementById('sbu-lib-status');
    var folderSelect = document.getElementById('sbu-folder');
    var folderLoad = document.getElementById('sbu-folder-load');
    var folderNew = document.getElementById('sbu-folder-new');
    var folderStatus = document.getElementById('sbu-folder-status');

    function pickerStatus(el,cls,text){el.className='sbu-picker-status show '+cls;el.textContent=text;if(cls==='ok')setTimeout(function(){el.classList.remove('show');},3000);}
    function btnLoading(btn,on){if(on)btn.classList.add('loading');else btn.classList.remove('loading');}
    function getFormCreds(){var u=document.querySelector('input[name="'+optKey+'[url]"]');var e=document.querySelector('input[name="'+optKey+'[user]"]');var p=document.querySelector('input[name="'+optKey+'[pass]"]');return '&sbu_url='+encodeURIComponent(u?u.value:'')+'&sbu_user='+encodeURIComponent(e?e.value:'')+'&sbu_pass='+encodeURIComponent(p?p.value:'');}

    // Load libraries
    libLoad.addEventListener('click',function(){
        btnLoading(libLoad,true);libStatus.className='sbu-picker-status';
        P('sbu_load_libs',getFormCreds()).then(function(d){
            btnLoading(libLoad,false);
            if(!d.success){pickerStatus(libStatus,'er',d.data||'Fehler');return;}
            var libs=d.data;
            libSelect.innerHTML='<option value="">— Bibliothek wählen —</option>';
            var cur = sbuAdmin.curLib;
            libs.forEach(function(l){
                var sel=l.name===cur?' selected':'';
                libSelect.innerHTML+='<option value="'+l.name+'"'+sel+'>'+l.name+' ('+Math.round(l.size/1024/1024)+' MB)</option>';
            });
            libSelect.disabled=false;
            pickerStatus(libStatus,'ok','✓ '+libs.length+' Bibliotheken');
            folderLoad.disabled=false;folderNew.disabled=false;
            if(libSelect.value)loadFolders();
        }).catch(function(x){btnLoading(libLoad,false);pickerStatus(libStatus,'er',x.message);});
    });

    // On library change, auto-load folders
    libSelect.addEventListener('change',function(){
        // Reset folder to root when library changes
        folderSelect.innerHTML='<option value="/" selected>/ (Stammverzeichnis)</option>';
        folderSelect.disabled=true;
        if(this.value){folderLoad.disabled=false;folderNew.disabled=false;loadFolders();}
        else{folderLoad.disabled=true;folderNew.disabled=true;}
    });

    // Load folders
    function loadFolders(){
        var lib=libSelect.value;if(!lib)return;
        btnLoading(folderLoad,true);folderStatus.className='sbu-picker-status';
        P('sbu_load_dirs','&lib='+encodeURIComponent(lib)+getFormCreds()).then(function(d){
            btnLoading(folderLoad,false);
            if(!d.success){pickerStatus(folderStatus,'er',d.data||'Fehler');return;}
            var dirs=d.data;
            folderSelect.innerHTML='<option value="/">/ (Stammverzeichnis)</option>';
            var cur = sbuAdmin.curFolder;
            dirs.forEach(function(dn){
                var val='/'+dn;var sel=val===cur?' selected':'';
                folderSelect.innerHTML+='<option value="'+val+'"'+sel+'>'+val+'</option>';
            });
            folderSelect.disabled=false;
            pickerStatus(folderStatus,'ok','✓ '+dirs.length+' Ordner');
        }).catch(function(x){btnLoading(folderLoad,false);pickerStatus(folderStatus,'er',x.message);});
    }
    folderLoad.addEventListener('click',loadFolders);

    // New folder
    var newdirForm = document.getElementById('sbu-newdir-form');
    var newdirInput = document.getElementById('sbu-newdir-input');
    folderNew.addEventListener('click',function(){newdirForm.style.display='flex';newdirInput.focus();});
    document.getElementById('sbu-newdir-cancel').addEventListener('click',function(){newdirForm.style.display='none';newdirInput.value='';});
    document.getElementById('sbu-newdir-create').addEventListener('click',function(){
        var name=newdirInput.value.trim();if(!name)return;
        var lib=libSelect.value;if(!lib){pickerStatus(folderStatus,'er','Zuerst Bibliothek wählen');return;}
        P('sbu_create_dir','&lib='+encodeURIComponent(lib)+'&dirname='+encodeURIComponent(name)+getFormCreds()).then(function(d){
            if(!d.success){pickerStatus(folderStatus,'er',d.data||'Fehler');return;}
            newdirForm.style.display='none';newdirInput.value='';
            pickerStatus(folderStatus,'ok','✓ Ordner erstellt');
            loadFolders();
            // Select the new folder after reload
            setTimeout(function(){folderSelect.value='/'+name;},500);
        }).catch(function(x){pickerStatus(folderStatus,'er',x.message);});
    });

    // Abort upload
    var sbuAborted = false;
    window.sbuAbort=function(){
        if(!confirm('Upload wirklich abbrechen? Der Fortschritt geht verloren. Zum kurzen Unterbrechen stattdessen Pause verwenden.'))return;
        sbuAborted=true;
        P('sbu_abort_upload').then(function(d){
            if(d.success){
                document.getElementById('sbu-upb').style.display='none';
                window.sbuKicking=false;
                refreshLog();
                // Reload page after short delay to reset all state
                setTimeout(function(){location.reload();},1500);
            }else{
                alert('Abbrechen fehlgeschlagen: '+(d.data||'Unbekannter Fehler')+'. Seite wird neu geladen.');
                location.reload();
            }
        }).catch(function(x){
            alert('Abbrechen fehlgeschlagen. Seite wird neu geladen.');
            location.reload();
        });
    };

    // Pause / Resume — keep offset so Resume picks up exactly where we stopped.
    window.sbuPause=function(){
        var btn=document.getElementById('sbu-pause');if(btn)btn.disabled=true;
        P('sbu_pause_upload').then(function(d){
            if(btn)btn.disabled=false;
            if(!d.success){alert('Pause fehlgeschlagen: '+(d.data||'Unbekannter Fehler'));return;}
            refreshLog();
            pollUploadStatus();
        }).catch(function(){if(btn)btn.disabled=false;alert('Pause fehlgeschlagen (Verbindungsfehler).');});
    };
    window.sbuResume=function(){
        var btn=document.getElementById('sbu-resume');if(btn)btn.disabled=true;
        P('sbu_resume_upload').then(function(d){
            if(btn)btn.disabled=false;
            if(!d.success){alert('Fortsetzen fehlgeschlagen: '+(d.data||'Unbekannter Fehler'));return;}
            refreshLog();
            pollUploadStatus();
        }).catch(function(){if(btn)btn.disabled=false;alert('Fortsetzen fehlgeschlagen (Verbindungsfehler).');});
    };

    // Upload progress polling
    var wasUploading = false;
    function pollUploadStatus(){
        P('sbu_upload_status').then(function(d){
            var p=d.data||{};
            var banner=document.getElementById('sbu-upb');
            if(p.active){
                wasUploading=true;
                banner.style.display='block';
                var title=document.querySelector('.sbu-up-title');
                var pauseBtn=document.getElementById('sbu-pause');
                var resumeBtn=document.getElementById('sbu-resume');
                if(p.paused){
                    title.textContent=(p.mode==='restore'?'\u23F8 Wiederherstellung pausiert':'\u23F8 Upload pausiert');
                    if(pauseBtn)pauseBtn.style.display='none';
                    if(resumeBtn)resumeBtn.style.display='';
                }else{
                    if(p.mode==='restore'){
                        title.textContent='\u23F3 Wiederherstellung von Seafile l\u00E4uft...';
                    }else{
                        title.textContent='\u23F3 Upload zu Seafile l\u00E4uft...';
                    }
                    if(pauseBtn)pauseBtn.style.display='';
                    if(resumeBtn)resumeBtn.style.display='none';
                }
                var info='Datei '+p.file_num+'/'+p.file_total+': '+p.file;
                if(p.ok>0||p.err>0)info+=' ('+p.ok+' OK'+(p.err>0?', '+p.err+' Fehler':'')+')';
                document.getElementById('sbu-upf').textContent=info;
                var pct=p.pct||0;
                document.getElementById('sbu-upfill').style.width=pct+'%';
                document.getElementById('sbu-uppct').textContent=pct+'%';
                // Actively drive upload processing from browser — but not while paused.
                if(!p.paused&&!window.sbuKicking&&!sbuAborted){
                    window.sbuKicking=true;
                    P('sbu_kick').then(function(){window.sbuKicking=false;refreshLog();pollUploadStatus();}).catch(function(){window.sbuKicking=false;});
                }
            }else{
                banner.style.display='none';
                window.sbuKicking=false;
                if(wasUploading){wasUploading=false;loadBL(true);refreshLog();}
            }
        }).catch(function(){window.sbuKicking=false;});
    }

    // Auto-refresh: poll every 30s for changes, upload status every 5s
    setInterval(function(){loadBL(true);refreshLog();},30000);
    setInterval(pollUploadStatus,5000);
    pollUploadStatus();

    // Refresh nonce every 30 minutes to prevent expiry on long sessions
    setInterval(function(){
        fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=sbu_refresh_nonce'})
        .then(function(r){return r.json();})
        .then(function(d){if(d.success&&d.data)N=d.data;});
    },1800000);
})();
