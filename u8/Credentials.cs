using System;
using System.IO;
using System.Text;
using System.Security.Cryptography;
namespace U8OmApi {
 // 用本机 DPAPI 加密凭据；部署目录 ACL 负责限制文件访问。
 internal sealed class Credentials {
  public string password;public string hmac;
  private static string PathName { get { return Path.GetFullPath(Environment.GetEnvironmentVariable("U8_OM_CREDENTIAL_FILE") ?? Path.Combine(AppDomain.CurrentDomain.BaseDirectory,"..","credentials.dpapi")); } }
  internal static void Save(string password,string hmac){
   if(String.IsNullOrEmpty(password)||String.IsNullOrEmpty(hmac)||hmac.Length<32||hmac.Length>256)throw new ApiError("CREDENTIAL_INPUT_INVALID");
   byte[] plain=Encoding.UTF8.GetBytes(Wire.Json.Serialize(new Credentials{password=password,hmac=hmac}));
   try{byte[] encrypted=ProtectedData.Protect(plain,null,DataProtectionScope.LocalMachine);
    using(var file=new FileStream(PathName,FileMode.CreateNew,FileAccess.Write,FileShare.None)){file.Write(encrypted,0,encrypted.Length);file.Flush(true);}
   }finally{Array.Clear(plain,0,plain.Length);}
  }
  internal static Credentials Read(){
   byte[] plain=ProtectedData.Unprotect(File.ReadAllBytes(PathName),null,DataProtectionScope.LocalMachine);
   try{var c=Wire.Json.Deserialize<Credentials>(Encoding.UTF8.GetString(plain));
    if(c==null||String.IsNullOrEmpty(c.password)||String.IsNullOrEmpty(c.hmac)||c.hmac.Length<32||c.hmac.Length>256)throw new ApiError("CREDENTIAL_FILE_INVALID");return c;
   }finally{Array.Clear(plain,0,plain.Length);}
  }
  // 仅清除字符串引用；明文字节缓冲区另由读写方法主动清零。
  internal void Clear(){password=null;hmac=null;}
 }
}
