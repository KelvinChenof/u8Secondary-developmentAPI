using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Windows.Forms;

namespace U8OmApi
{
    // HTTP 主进程接收请求，U8 原生调用由独立工作进程执行。
    internal static class Program
    {
        private static string Prompt(string title, string label)
        {
            using (Form form = new Form { Text = title, Width = 520, Height = 185, StartPosition = FormStartPosition.CenterScreen, FormBorderStyle = FormBorderStyle.FixedDialog, MaximizeBox = false, MinimizeBox = false })
            {
                Label caption = new Label { Text = label, Left = 16, Top = 15, Width = 470, Height = 40 };
                TextBox input = new TextBox { Left = 16, Top = 60, Width = 470, UseSystemPasswordChar = true };
                Button ok = new Button { Text = "继续", Left = 310, Top = 98, DialogResult = DialogResult.OK };
                Button cancel = new Button { Text = "取消", Left = 400, Top = 98, DialogResult = DialogResult.Cancel };
                form.Controls.Add(caption); form.Controls.Add(input); form.Controls.Add(ok); form.Controls.Add(cancel);
                form.AcceptButton = ok; form.CancelButton = cancel;
                if (form.ShowDialog() != DialogResult.OK) throw new ApiError("CANCELLED");
                string value = input.Text; input.Clear(); return value;
            }
        }
        // U8 COM 组件使用 STA 线程；启动自检不登录，只读探测会实际登录。
        [STAThread]
        private static int Main(string[] args) {
            if(args.Length==1 && args[0]=="--native-worker") return Worker.Execute();
            if(args.Length==1 && args[0]=="--startup-check") {Console.WriteLine("ISOLATED_BUILD_READY; login=not_called");return 0;}
            if(args.Length==1 && args[0]=="enroll") {
                try {Application.EnableVisualStyles();Credentials.Save(Prompt("U8 credentials","U8 password"),Prompt("OA credentials","Original HMAC key"));Console.WriteLine("CREDENTIALS_ENROLLED");return 0;}
                catch {Console.Error.WriteLine("CREDENTIAL_ENROLL_FAILED");return 1;}
            }
            if(args.Length!=1 || (args[0]!="serve-unattended" && args[0]!="serve" && args[0]!="probe-unattended")) {
                Console.Error.WriteLine("Use serve-unattended or probe-unattended. No legacy in-process native mode.");return 2;
            }
            bool owned=false;
            // 同一账套只允许一个接口主进程，防止多个实例同时接单。
            using(var mutex=new Mutex(false,@"Global\ExampleOrg-U8OmApi-IsolatedHost-999-2026")) {
                try {
                    try{owned=mutex.WaitOne(0);}catch(AbandonedMutexException){owned=true;}
                    if(!owned)throw new ApiError("ANOTHER_ISOLATED_HOST_IS_RUNNING");
                    Settings settings=Worker.ReadSettings();
                    if(args[0]=="probe-unattended") {
                        if(settings.audit_enabled)throw new ApiError("PROBE_REQUIRES_AUDIT_DISABLED");
                        object page=IsolatedHost.Dispatch(new WorkerRequest{Operation="list",Cursor=0});
                        Console.WriteLine("SYSTEM_READ_PROBE_OK result="+Wire.Json.Serialize(page));return 0;
                    }
                    var credentials=Credentials.Read();Authenticator auth;
                    try{auth=new Authenticator(credentials.hmac);}finally{credentials.Clear();}
                    IsolatedHost.Serve(settings,auth);return 0;
                }catch(Exception e){var known=e as ApiError;Console.Error.WriteLine("HOST_FAILED code="+(known==null?"HOST_RUNTIME_ERROR":known.Code));return 1;}
                finally{if(owned)mutex.ReleaseMutex();}
            }
        }
    }
}
